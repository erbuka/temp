<?php


namespace App\Command;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Service;
use App\Entity\Task;
use App\Entity\Schedule;
use App\Slot;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Connection;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(
    name: 'app:schedule',
    description: 'Generates a schedule for the planned activities'
)]
class ScheduleActivities extends Command
{
    const DATE_NOTIME = 'Y-m-d';
    const CONTRACTED_SERVICES_VIEW = 'contracted_service_extd';

    protected \DateTimeInterface $from;
    protected \DateTimeInterface $to;
    protected \DateTimeZone $timezone;
    protected EntityManagerInterface $entityManager;
    protected Connection $rawConnection;
    protected Connection $connection;
    protected ValidatorInterface $validator;
    protected OutputInterface $output;
    protected InputInterface $input;

    /** @var Service[] */
    private array $services;
    /** @var array<string, array<string, number>> */
    private array $serviceSlots; // ['service name' => ['start' => 12, 'end' => 123]

//    private Schedule $schedule;

    public function __construct(EntityManagerInterface $entityManager, Connection $rawConnection, Connection $defaultConnection,  ValidatorInterface $validator)
    {
        $this->entityManager = $entityManager;
        $this->rawConnection = $rawConnection;
        $this->connection = $defaultConnection;
        $this->validator = $validator;

        $this->from = \DateTimeImmutable::createFromFormat(DATE_ATOM,'2021-07-01T00:00:00Z');
        $this->to = \DateTimeImmutable::createFromFormat(DATE_ATOM, '2022-06-30T23:59:59Z');

        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;

        $output->writeln(sprintf('Scheduling activities from %s to %s', $this->from->format(DATE_RFC3339), $this->to->format(DATE_RFC3339)));

        $this->preloadServices();
        $schedule = new Schedule($this->from, $this->to);

        foreach ($this->entityManager->getRepository(Consultant::class)->findAll() as $consultant) {
            /** @var Consultant $consultant */
            $consultantSchedule = new Schedule($this->from, $this->to);

            // !!! TODO for each service, $scheduler->getRandomFreeSlot($service->start, $service->end)
            // TODO check start and end bounderies, e.g. end=2021-10-01T00:00 must include somehow 2021-10-01T18:00

            // Allocate Initial Slot for each contracted service
            /** @var array<int, Slot> $initialSlots */
            $initialSlots = [];
            $contractedServices = $this->entityManager->getRepository(ContractedService::class)->findBy(['consultant' => $consultant]);
            foreach ($contractedServices as $contractedService) {
                $recipient = $contractedService->getRecipient();
                $service = $contractedService->getService();

                assert($recipient !== null, "ContractedService has recipient === null");
                assert($service !== null, "ContractedService has service === null");

                $slot = $consultantSchedule->getRandomFreeSlot($service->getFromDate(), $service->getToDate());

                $task = new Task();
                $task->setContractedService($contractedService);
                $task->setStart($slot->getStart()); // truncated by precision
                $task->setEnd($slot->getEnd()); // truncated by precision
                $task->setOnPremises(false);

                if (count($errors = $this->validator->validate($task)) > 0) {
                    throw new \Exception("Cannot validate task ({$task->getConsultant()->getName()}, {$task->getRecipient()->getName()}, {$task->getService()->getName()}): ". $errors);
                }

                $this->entityManager->persist($task);

                $slot->addTask($task);
                $consultantSchedule->addTask($task);
                $initialSlots[] = $slot;

//                $this->output->writeln(sprintf('[%s] Allocated slot %s to service "%s" for client "%s"',
//                    $consultant->getName(),
//                    $slot->getPeriod()->asString(),
//                    $service->getName(),
//                    $recipient->getName()
//                ));
            };
            assert(count($initialSlots) === count($contractedServices), "Did not allocate 1 slot for each contracted service");

            // 1. for each initial slot, expand the slot until either 1) no more free slots left, 2) all hours have been allocated
            $watchdog = 10000;
            $csHoursMap = new \SplObjectStorage();
            do {
                foreach ($initialSlots as $initialSlot) {
                    assert(count($initialSlot->getTasks()) === 1);
                    assert($watchdog-- > 0);

                    /** @var Task $initialTask */
                    $initialTask = current($initialSlot->getTasks());
                    $contractedService = $initialTask->getContractedService();
                    $allocatedSlotsCount = $consultantSchedule->countSlotsAllocatedToContractedService($contractedService);
                    $hoursLeft = $contractedService->getService()->getHours() - $allocatedSlotsCount;
                    assert($hoursLeft >= 0, "left={$hoursLeft} for ('{$contractedService->getService()->getName()}', '{$contractedService->getRecipientName()}')");

                    if ($hoursLeft <= 0) {
//                        $this->output->writeln(sprintf("<info>ALL HOURS ALLOCATED FOR</info> '%s' '%s'", $contractedService->getService()->getName(), $contractedService->getRecipientName()));
                        continue;
                    }

                    $slots = $consultantSchedule->allocateContractedServicePass($initialSlot, $contractedService, min($hoursLeft, 5));

                    usort($slots, fn(/** @var Slot $s1 */ $s1, /** @var Slot $s2 */ $s2) => $s1->getStart() <=> $s2->getStart());

                    if (empty($slots)) {
                        throw new \LogicException("no more slots available");
                    }

                    $task = new Task();
                    $task->setContractedService($initialTask->getContractedService());
                    $task->setStart(reset($slots)->getStart());
                    $task->setEnd(end($slots)->getEnd());
                    $task->setOnPremises(false);
                    $consultantSchedule->addTask($task);

                    if (count($errors = $this->validator->validate($task)) > 0) {
                        throw new \Exception("Cannot validate task ({$task->getConsultant()->getName()}, {$task->getRecipient()->getName()}, {$task->getService()->getName()}): ". $errors);
                    }

                    $this->entityManager->persist($task);

                    foreach ($slots as $slot) {
                        /** @var Slot $slot */
                        $slot->addTask($task);
                    }

                    // Updates allocataed hours for this contracted service
                    $allocatedSlotsCount = $consultantSchedule->countSlotsAllocatedToContractedService($contractedService);
                    $csHoursMap[$contractedService] = $contractedService->getService()->getHours() - $allocatedSlotsCount;

                    $this->output->writeln("Allocated {$allocatedSlotsCount} slots to ('{$contractedService->getService()->getName()}' '{$contractedService->getRecipientName()}'");
                }

                $toAllocate = array_filter(iterator_to_array($csHoursMap), fn($cs) => $csHoursMap[$cs] > 0);
                $this->output->writeln("<info>TO ALLOCATE</info> another ". count($toAllocate) ." contracted services for {$consultant->getName()}");
            } while (count($toAllocate) > 0);

//            $this->entityManager->persist($consultantSchedule);
            $schedule->merge($consultantSchedule);

            $this->output->writeln(sprintf("<info>Schedule for %s</info> %s", $consultant->getName(), $consultantSchedule->getStats()));
        }

        $this->output->writeln("<info>Generated schedule</info> {$schedule->getStats()}");

        $this->entityManager->persist($schedule);
        $this->entityManager->flush();

        return Command::SUCCESS;
    }

    /**
     * (consultant, recipient, hours, activity_type)
     */
    protected function getConsultingContracts() {

    }

    /**
     */
    protected function rankServices(\DateTimeInterface $date, Recipient $recipient, Consultant $consultant)
    {
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

    private function preloadServices()
    {
        foreach ($this->entityManager->getRepository(Service::class)->findAll() as /** @var Service $service */ $service) {
            if (!$service->getToDate() || $service->getToDate() > $this->to)
                $service->setToDate($this->to);

            if (!$service->getFromDate() || $service->getFromDate() < $this->from)
                $service->setFromDate($this->from);

            $this->services[$service->getName()] = $service;
        }
    }

    /**
     * @param Schedule $schedule assumed to contain only tasks for a single consultant
     */
    private function allocationPass(Schedule $schedule)
    {

    }
}
