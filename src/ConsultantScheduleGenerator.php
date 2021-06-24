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
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\PeriodCollection;
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
    protected ScheduleManagerFactory $scheduleManagerFactory;

    /** @var array<int, ContractedService> */
    private array $contractedServices = [];

    private \SplObjectStorage $contractedServiceBoundaries;
    private \SplObjectStorage $allocatedSlots;
    private OutputInterface $output;

    public function __construct(EntityManagerInterface $entityManager, ValidatorInterface $validator, ScheduleManagerFactory $scheduleManagerFactory)
    {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->scheduleManagerFactory = $scheduleManagerFactory;
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
        $manager = $this->scheduleManagerFactory->createScheduleManager($schedule);

        $this->allocateOnPremisesHours($manager);
//        $initialSlots = $this->allocateInitialSlots($manager);

        // N.B. Slots will contain at most 1 task because individual consultant schedules do not allow overlap

//        $dog = 10000;
//        do {
//            $this->allocationPass($schedule, $initialSlots);
//            $unallocatedHours = $contractedHours - count($this->allocatedSlots);
//        } while ($unallocatedHours > 0 && $dog-- > 0);
//        assert($dog > 0, "Watchdog triggered!");

        $schedule->assertZeroOrOneTaskPerSlot();
        $manager->consolidateSameDayAdjacentTasks();
        $schedule->consolidateNonOverlappingTasksDaily();

        // Validate consultant Schedule
        if (count($errors = $this->validator->validate($schedule, null, ['Default', 'consultant'])) > 0)
            throw new \Exception("Invalid schedule for consultant {$consultant}:". $errors);

        return $schedule;
    }

    private function allocateOnPremisesHours(ScheduleManager $manager): void
    {
        assert(count($this->allocatedSlots) === 0, "On premises hours must be allocated before others");

        // expand initial slots by
        // 1) allocate on the same day the hours established by each service for on premises consultancy
        // 2) allocate in tasks distant between 7-14 days the remaining hours

        $schedule = $manager->getSchedule();

        foreach ($this->contractedServices as $contractedService) {
            $recipient = $contractedService->getRecipient();
            $service = $contractedService->getService();

            assert($contractedService->getConsultant() === $this->consultant, "ContractedService has consultant !== {$this->consultant}");
            assert($recipient !== null, "ContractedService has recipient === null");
            assert($service !== null, "ContractedService has service === null");
            assert($service->getHoursOnPremises() > 0, "Service does not have any on-premises hours");

            $onPremisesHours = $service->getHoursOnPremises();
            $preferredHours = $service->getTaskPreferredOnPremisesHours() ?? 2;

            /** @var \DateTimeImmutable $head */
            /** @var \DateTimeImmutable $tail  */
            $head = $tail = null;
            while ($onPremisesHours > 0) {
                if (!$head || !$tail)
                    $backwardsPeriod = $forwardsPeriod = $schedule->getPeriod();
                else {
                    $backwardsPeriod = Period::make(
                        $head->sub(new \DateInterval('P14D')),
                        $head->sub(new \DateInterval('P7D')),
                        Precision::HOUR(), Boundaries::EXCLUDE_END()
                    );

                    $forwardsPeriod = Period::make(
                        $tail->add(new \DateInterval('P7D')),
                        $tail->add(new \DateInterval('P14D')),
                        Precision::HOUR(), Boundaries::EXCLUDE_NONE()
                    );

                    $backwardsPeriod = $backwardsPeriod->overlap($schedule->getPeriod());
                    $forwardsPeriod = $forwardsPeriod->overlap($schedule->getPeriod());
                }

                if (!$backwardsPeriod)
                    $direction = true;
                elseif (!$forwardsPeriod)
                    $direction = false;
                else
                    $direction = (bool)rand(0, 1);

                $period = match ($direction) {
                    false => $backwardsPeriod,
                    true => $forwardsPeriod
                };

                if (!$period) $period = $schedule->getPeriod();

                if ($period->length() < 2*10)
                    $period = $schedule->getPeriod();

                assert($period !== null, "Unable to select a period to be used to fetch random slots");
                assert($period->overlapsWith($schedule->getPeriod()));

                $slots = $manager->getRandomFreeSlotsSameDay(
                    preferred: min($preferredHours, $onPremisesHours),
                    period: $period
                );
                if (empty($slots))
                    $slots = $manager->getRandomFreeSlotsSameDay(
                        preferred: min($preferredHours, $onPremisesHours),
                        period: $period
                    );
                if (empty($slots))
                    $slots = $manager->getRandomFreeSlotsSameDay(
                        preferred: min($preferredHours, $onPremisesHours),
                        period: $schedule->getPeriod()
                    );

                if (count($slots) === 0) {
                    throw new \RuntimeException('No slots allocated. Out of slots?');
                }

                foreach ($slots as $slot) {
                    $task = new Task();
                    $task->setContractedService($contractedService);
                    $task->setStart($slot->getStart()); // truncated by precision
                    $task->setEnd($slot->getEnd()); // truncated by precision
                    $task->setOnPremises(true);

                    if (count($errors = $this->validator->validate($task)) > 0)
                        throw new \Exception("Cannot validate task {$task} of contracted service {$contractedService}: ". $errors);

                    $slot->addTask($task);
                    $manager->addTask($task);
                    $this->allocatedSlots->attach($slot);

                    if (!$head || $slot->getStart() < $head)
                        $head = \DateTimeImmutable::createFromInterface($slot->getStart()); // to be excluded in backwards
                    if (!$tail || $slot->getEnd() > $tail)
                        $tail = \DateTimeImmutable::createFromInterface($slot->getEnd());
                }

                $onPremisesHours -= count($slots);

                $this->output->writeln(
                    sprintf("<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> Allocated %d on premises hours", $contractedService->getId(), count($slots)),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }

            $all = $schedule->countSlotsAllocatedToContractedService($contractedService);

            assert($all == $service->getHoursOnPremises(), "Allocated {$all} != {$service->getHoursOnPremises()} hours for contracted service {$contractedService}");
        }
    }

    /**
     * Initial slots are all on premises.
     *
     * @param Schedule $schedule
     * @return array<int, Slot> Initial slots
     * @throws \Exception
     */
    private function allocateInitialSlots(ScheduleManager $manager): array
    {
        $schedule = $manager->getSchedule();
        $allocatedSlots = [];

        foreach ($this->contractedServices as $contractedService) {
            $recipient = $contractedService->getRecipient();
            $service = $contractedService->getService();

            assert($contractedService->getConsultant() === $this->consultant, "ContractedService has consultant !== {$this->consultant}");
            assert($recipient !== null, "ContractedService has recipient === null");
            assert($service !== null, "ContractedService has service === null");
            assert($service->getHoursOnPremises() > 0, "Service does not have any on-premises hours");
            assert($service->getHours() > 0, "Service {$service} does not have any hours to allocate");

            // Allocate initial task for remote hours
            $slot = $manager->getRandomFreeSlot();
            if (!$slot)
                throw new \RuntimeException('Out of slots');

            assert(!$this->allocatedSlots->contains($slot), "Slot {$slot} already allocated ?!");

            $task = new Task();
            $task->setContractedService($contractedService);
            $task->setStart($slot->getStart()); // truncated by precision
            $task->setEnd($slot->getEnd()); // truncated by precision
            $task->setOnPremises(false);

            if (count($errors = $this->validator->validate($task)) > 0)
                throw new \Exception("Cannot validate task {$task} of contracted service {$contractedService}: ". $errors);

            $slot->addTask($task);
            $manager->addTask($task);
            $this->allocatedSlots->attach($slot);
            $allocatedSlots[] = $slot;

            $this->output->writeln(
                sprintf("<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> Allocated initial slots {$slot}", $contractedService->getId()),
                OutputInterface::VERBOSITY_VERBOSE
            );
        }

//        $this->output->writeln(sprintf("<info>Allocated %s initial slots for %s</info>", count($this->allocatedSlots), $this->consultant));
//        assert(count($this->allocatedSlots) === count($this->contractedServices) * 2, "Did not allocate 2 slots for each contracted service");

        return $allocatedSlots;
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
                $slots = $schedule->allocateContractedServicePass($startingSlot, min($remainingHoursOnPremises, 5));
            } else {
                assert($remainingHoursRemote > 0, "Requesting slots for remainingHoursRemote={$remainingHoursRemote} <= 0");
                $slots = $schedule->allocateContractedServicePass($startingSlot, min($remainingHoursRemote, 5));
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

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

}
