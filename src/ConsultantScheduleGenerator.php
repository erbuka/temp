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
use Symfony\Component\Workflow\WorkflowInterface;

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
//    protected WorkflowInterface $taskWorkflow;

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
//        $this->taskWorkflow = $taskWorkflow;
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
        $schedule->setConsultant($consultant);
        $manager = $this->scheduleManagerFactory->createScheduleManager($schedule);

        $this->allocateOnPremisesHours($schedule);

//        $initialSlots = $this->allocateInitialSlots($manager);

        // N.B. Slots will contain at most 1 task because individual consultant schedules do not allow overlap

//        $dog = 10000;
//        do {
//            $this->allocationPass($schedule, $initialSlots);
//            $unallocatedHours = $contractedHours - count($this->allocatedSlots);
//        } while ($unallocatedHours > 0 && $dog-- > 0);
//        assert($dog > 0, "Watchdog triggered!");

        $manager->consolidateSameDayAdjacentTasks();
//        $manager->consolidateNonOverlappingTasksDaily();

        // Validate consultant Schedule
        if (count($errors = $this->validator->validate($manager, null, ['Default', 'consultant', 'generation'])) > 0)
            throw new \Exception("Invalid schedule for consultant {$consultant}:". $errors);

        return $schedule;
    }

    private function allocateOnPremisesHours(Schedule $schedule): void
    {
        assert(count($this->allocatedSlots) === 0, "On premises hours must be allocated before others");
        $manager = $this->scheduleManagerFactory->createScheduleManager($schedule);

        // expand initial slots by
        // 1) allocate on the same day the hours established by each service for on premises consultancy
        // 2) allocate in tasks distant between 7-14 days the remaining hours

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
                    $backwardsPeriod = $forwardsPeriod = $manager->getSchedulePeriod();
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

                    $backwardsPeriod = $backwardsPeriod->overlap($manager->getSchedulePeriod());
                    $forwardsPeriod = $forwardsPeriod->overlap($manager->getSchedulePeriod());
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

                $mirrorPeriod = match ($period) {
                    $backwardsPeriod => $forwardsPeriod,
                    $forwardsPeriod => $backwardsPeriod,
                };

                if (!$period || $period->length() < 2*10) $period = $manager->getSchedulePeriod();

                assert($period !== null, "Unable to select a period to be used to fetch random slots");
                assert($period->overlapsWith($manager->getSchedulePeriod()));

                // TODO try getting random adjacent slots on the same day
                // if that fails, then just randomly allocate on the same day ** AND CONSOLIDATE + COMPACT **

                $task = new Task();
                $task->setContractedService($contractedService);
                $task->setOnPremises(true);
//                $task->setStart($slot->getStart()); // truncated by precision
//                $task->setEnd($slot->getEnd()); // truncated by precision

                try {
                    $manager->allocateAdjacentSameDayFreeSlots($task,
                        preferred: min($preferredHours, $onPremisesHours),
                        period: $period,
                    );
                } catch (NoFreeSlotsAvailableException $e) {
                    try {
                        $manager->allocateAdjacentSameDayFreeSlots($task,
                            preferred: min($preferredHours, $onPremisesHours),
                            period: $mirrorPeriod ?? $manager->getSchedulePeriod(),
                        );
                    } catch (NoFreeSlotsAvailableException $e) {
                        $manager->allocateAdjacentSameDayFreeSlots($task,
                            preferred: min($preferredHours, $onPremisesHours),
                            period: $manager->getSchedulePeriod()
                        );
                    }
                }

                if (count($errors = $this->validator->validate($task)) > 0)
                    throw new \Exception("Cannot validate task {$task} of contracted service {$contractedService}: ". $errors);


                if (!$head || $task->getStart() < $head)
                    $head = \DateTimeImmutable::createFromInterface($task->getStart()); // to be excluded in backwards
                if (!$tail || $task->getEnd() > $tail)
                    $tail = \DateTimeImmutable::createFromInterface($task->getEnd());


                $onPremisesHours -= $task->getHours();

                $this->output->writeln(
                    sprintf("<info>[ALLOCATION PASS cs=<fg=cyan>%-3d</>]</info> Allocated %d on premises hours of preferred %d", $contractedService->getId(), $task->getHours(), $preferredHours),
                    OutputInterface::VERBOSITY_VERBOSE
                );
            }

            $all = $manager->countSlotsAllocatedToContractedService($contractedService);

            assert($all == $service->getHoursOnPremises(), "Allocated {$all} != {$service->getHoursOnPremises()} hours for contracted service {$contractedService}");
        }
    }

    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

}
