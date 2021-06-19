<?php


namespace App;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Schedules contracted services of a single consultant.
 *
 * The result is usually merged with other consultant schedules and persisted
 * as a single schedule.
 */
class ConsultantScheduleGenerator
{
    protected EntityManagerInterface $entityManager;
    protected Consultant $consultant;
    protected ValidatorInterface $validator;

    /** @var array<int, ContractedService> */
    private array $contractedServices = [];

    private \SplObjectStorage $contractedServiceBoundaries;
    private \SplObjectStorage $allocatedSlots;
    private OutputInterface $output;

    public function __construct(EntityManagerInterface $entityManager, ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
    }

    /**
     * @param Consultant $consultant
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $to
     * @return Schedule
     * @throws \Exception
     */
    public function generateSchedule(Consultant $consultant, \DateTimeInterface $from, \DateTimeInterface $to): Schedule
    {
        $this->consultant = $consultant;
        $this->allocatedSlots = new \SplObjectStorage;
        $this->contractedServiceBoundaries = new \SplObjectStorage;
        $this->contractedServices = $this->entityManager->getRepository(ContractedService::class)->findBy(['consultant'=> $consultant]);

        // Total hours to allocate equals the sum of all contracted service hours
        $contractedHours = array_reduce($this->contractedServices, fn($sum, $cs) => /** @var ContractedService $cs */ $sum + $cs->getHours(), 0);
        $schedule = new Schedule($from, $to);

        $this->allocateInitialSlots($schedule);
        $initialSlots = iterator_to_array($this->allocatedSlots);

        // N.B. Slots will contain at most 1 task because individual consultant schedules do not allow overlap
        // TODO detect whether a slot has more than 1 task

        $dog = 10000;
        do {
            $this->allocationPass($schedule, $initialSlots);
            $unallocatedHours = $contractedHours - count($this->allocatedSlots);
        } while ($unallocatedHours > 0 && $dog-- > 0);
        assert($dog > 0, "Watchdog triggered!");

        $schedule->assertZeroOrOneTaskPerSlot();

        // Validate consultant Schedule
        if (count($errors = $this->validator->validate($schedule, null, ['Default', 'consultant'])) > 0)
            throw new \Exception("Invalid schedule for consultant {$consultant}:". $errors);

        return $schedule;
    }

    /**
     * @param Schedule $schedule
     * @return array<int, Slot> Initial slots
     * @throws \Exception
     */
    private function allocateInitialSlots(Schedule $schedule): void
    {
        foreach ($this->contractedServices as $contractedService) {
            $recipient = $contractedService->getRecipient();
            $service = $contractedService->getService();

            assert($contractedService->getConsultant() === $this->consultant, "ContractedService has consultant !== {$this->consultant}");
            assert($recipient !== null, "ContractedService has recipient === null");
            assert($service !== null, "ContractedService has service === null");
            assert($service->getHours() > 0, "Service {$service} does not have any hours to allocate");

            $slot = $schedule->getRandomFreeSlot($service->getFromDate() ?? $schedule->getFrom(), $service->getToDate() ?? $schedule->getTo());
            assert(!$this->allocatedSlots->contains($slot), "Slot {$slot} already allocated ?!");

            $task = new Task();
            $task->setContractedService($contractedService);
            $task->setStart($slot->getStart()); // truncated by precision
            $task->setEnd($slot->getEnd()); // truncated by precision
            $task->setOnPremises((bool) rand(0, 1));

            $slot->addTask($task);
            $schedule->addTask($task);

            if (count($errors = $this->validator->validate($task)) > 0)
                throw new \Exception("Cannot validate task {$task} of contracted service {$contractedService}: ". $errors);

//            $this->entityManager->persist($task);

            $this->allocatedSlots->attach($slot);

            assert($a = $schedule->countSlotsAllocatedToContractedService($contractedService) === 1, "Allocated {$a} != 1 slots in first allocation step for contracted service {$contractedService}");
            $this->output->writeln(
                sprintf("<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> Allocated initial slot {$slot}", $contractedService->getId()),
                OutputInterface::VERBOSITY_VERBOSE
            );
        }

//        $this->output->writeln(sprintf("<info>Allocated %s initial slots for %s</info>", count($this->allocatedSlots), $this->consultant));
        assert(count($this->allocatedSlots) === count($this->contractedServices), "Did not allocate 1 slot for each contracted service");
    }

    /**
     * Expands given slots.
     *
     * @param Schedule $schedule
     * @param array $startingSlots
     * @throws \Exception
     */
    private function allocationPass(Schedule $schedule, array $startingSlots)
    {
        foreach ($startingSlots as $startingSlot) {
            assert(count($startingSlot->getTasks()) === 1, "A single consultant schedule cannot have more than 1 tasks per slot");

            /** @var Task $slotTask */
            $slotTask = current($startingSlot->getTasks());
            $contractedService = $slotTask->getContractedService();

            $allocatedSlotsCount = $schedule->countSlotsAllocatedToContractedService($contractedService);
            $allocatedOnPremisesSlotsCount = $schedule->countOnPremisesSlotsAllocatedToContractedService($contractedService);
            $allocatedRemoteSlotsCount = $allocatedSlotsCount - $allocatedOnPremisesSlotsCount;

            $remainingHours = $contractedService->getService()->getHours() - $allocatedSlotsCount;
            $remainingHoursOnPremises = $contractedService->getService()->getHoursOnPremises() - $allocatedOnPremisesSlotsCount;
            $remainingHoursRemote = $remainingHours - $remainingHoursOnPremises;

            $this->output->writeln(
                sprintf("\n<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> Expanding from <fg=red>%2d</> slots of which <fg=yellow>%2d</> are on premises. Remaining <fg=red>%2d</> hours of which <fg=yellow>%2d</> are on premises.",
                $contractedService->getId(), $allocatedSlotsCount, $allocatedOnPremisesSlotsCount, $remainingHours, $remainingHoursOnPremises),
                OutputInterface::VERBOSITY_VERBOSE,
            );

            assert($remainingHours >= 0 && $remainingHoursOnPremises >= 0, "left={$remainingHours} left_on_premises={$remainingHoursOnPremises} for cs={$contractedService->getId()}");

            if ($remainingHours === 0) {
                $this->output->writeln(
                    sprintf("<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> skipped because all hours have been allocated", $contractedService->getId()),
                    OutputInterface::VERBOSITY_VERBOSE,
                );
                continue;
            }

            if ($remainingHoursOnPremises <= 0) {
                $onPremises = false;
            } elseif ($remainingHoursRemote <= 0) {
                $onPremises = true;
            } else {
                $onPremises = (bool) rand(0, 1);
            }

            if ($onPremises && $remainingHoursOnPremises > 0) {
                $slots = $schedule->allocateContractedServicePass($startingSlot, $contractedService, min($remainingHoursOnPremises, 5));
            } else {
                assert($remainingHoursRemote > 0, "Requesting slots for remainingHoursRemote={$remainingHoursRemote} <= 0");
                $slots = $schedule->allocateContractedServicePass($startingSlot, $contractedService, min($remainingHoursRemote, 5));
            }
            if (empty($slots)) throw new \LogicException("no more slots available");

            // Sort slots by starting datetime
            usort($slots, fn(/** @var Slot $s1 */ $s1, /** @var Slot $s2 */ $s2) => $s1->getStart() <=> $s2->getStart());

            $task = new Task();
            $task->setContractedService($contractedService);
            $task->setStart(reset($slots)->getStart());
            $task->setEnd(end($slots)->getEnd());
            $task->setOnPremises($onPremises);
            $schedule->addTask($task);

            if (count($errors = $this->validator->validate($task)) > 0)
                throw new \Exception("Cannot validate task ({$task->getConsultant()->getName()}, {$task->getRecipient()->getName()}, {$task->getService()->getName()}): ". $errors);

            $this->entityManager->persist($task);

            foreach ($slots as $slot) {
                /** @var Slot $slot */
                $slot->addTask($task);
                $this->allocatedSlots->attach($slot);
            }

            $this->output->writeln(sprintf("<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> Allocated new  %12s task to <fg=green>%d</> slots: task=%s slots=%s",
                $contractedService->getId(),
                $onPremises ? '<fg=yellow>on-premises</>' : 'from remote',
                count($slots),
                $task,
                array_reduce($slots, fn($string, $slot) => "{$string} {$slot}", '')),
                OutputInterface::VERBOSITY_VERBOSE
            );
        }
    }

    protected function ___rankServices(\DateTimeInterface $date, Recipient $recipient, Consultant $consultant)
    {
        throw new \RuntimeException('Not implmented');

        $services = [];

        $contract = $this->entityManager->getRepository(Contract::class)->findOneBy(['recipient' => $recipient]);
        assert($contract !== null);
        $contractedServices = $this->entityManager->getRepository(ContractedService::class)->findBy([
            'consultant' => $consultant,
            'contract' => $contract,
        ]);

        foreach ($contractedServices as $contractedService) {
            $service = $contractedService->getService();
            if (!$service->getDateBefore()) {
                $service->setDateBefore($this->to);
            }
            if (!$service->getDateAfter()) $service->setDateAfteR($this->from);

            $period = Period::make($service->getDateAfter(), $service->getDateBefore(), Precision::DAY());
            if (!$period->contains($date))
                continue;

            $services[] = $service;
        }

        usort($services, function (/** @var Service $a */ $a, /** @var Service $b */ $b) {
            $a_interval = $a->getToDate()->diff($a->getFromDate(), true);
            $b_interval = $b->getDateBefore()->diff($b->getDateAfter(), true);

            return $a_interval->days <=> $b_interval->days;
        });

        return $services;
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

}
