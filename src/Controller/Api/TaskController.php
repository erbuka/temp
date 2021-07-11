<?php


namespace App\Controller\Api;

use App\ConsultantSchedule;
use App\Entity\Consultant;
use App\Entity\ContractedService;
use App\Entity\Schedule;
use App\Entity\Task;
use App\NoFreeSlotsAvailableException;
use App\Repository\ScheduleRepository;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsController]
#[Route('/tasks', name: 'calendar-tasks_')]
class TaskController extends AbstractController
{
    /**
     * Returns all tasks belonging to a schedule.
     *
     * filter[schedule]: Task::scheduleId
     * filter[consultant]: Consultant::name
     * filter[from]: DATE_ATOM
     * filter[to]: DATE_ATOM
     *
     * @return JsonResponse
     */
    #[Route(name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $em, Connection $defaultConnection, Request $request): Response
    {
        // Detect filters early in order to avoid computing expensive defaults (e.g. fetch entities)
        $filter = $request->query->get('filter', []);

        // Parse filters
        if (isset($filter['from'])) {
            $filter['from'] = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $filter['from']);
            if (!$filter['from']) throw new BadRequestHttpException("Invalid from date");
        }
        if (isset($filter['to'])) {
            $filter['to'] = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $filter['to']);
            if (!$filter['to']) throw new BadRequestHttpException("Invalid to date");
        }
        if (isset($filter['schedule'])) {
            $filter['schedule'] = $em->getRepository(Schedule::class)->find($filter['schedule']);
            if (!$filter['schedule']) $this->createNotFoundException("Invalid schedule");
        }
        // TODO check permissions
        if (isset($filter['consultant'])) {
            $filter['consultant'] = $em->getRepository(Consultant::class)->find($filter['consultant']);
            if (!$filter['consultant']) $this->createNotFoundException("Invalid consultant");
        }

        // Defaults
        if (!isset($filter['schedule'])) {
            // Fetch the latest consultant schedule
            assert(isset($filter['consultant']), "Cannot determine which schedule to fetch");
            $filter['schedule'] = $em->getRepository(Schedule::class)->findOneBy(['consultant' => $filter['consultant']]);
        }

        // Base query
        $qb = $em->createQueryBuilder();
        $qb->select('t')
            ->from(Task::class, 't')
            ->where('t.schedule = :schedule')
            ->orderBy('t.start', 'DESC')
        ;

        // Required parameters
        $qb->setParameter('schedule', $filter['schedule']);

        // Optional parameters
        if (isset($filter['from'])) {
            $qb->andWhere('t.start >= :from');
            $qb->setParameter('from', $filter['from'], Types::DATETIME_IMMUTABLE);
        }
        if (isset($filter['to'])) {
            $qb->andWhere('t.end <= :to');
            $qb->setParameter('to', $filter['to'], Types::DATETIME_IMMUTABLE);
        }
        if (isset($filter['consultant'])) {
            $qb->andWhere('t.consultantName = :consultant');
            $qb->setParameter('consultant', $filter['consultant']->getName());
        }


        $q = $qb->getQuery();
        $tasks = $qb->getQuery()->getResult();

        $data = [];
        foreach ($tasks as $task) {
            /** @var Task $task */
            $isOnPremises = $task->isOnPremises();

            $data[] = [
                'id' => $task->getId(),
                'start' => $task->getStart()->format(DATE_ATOM),
                'end' => $task->getEnd()->format(DATE_ATOM),
//                'title' => "{$task->getService()} @ {$task->getRecipient()->getHeadquarters()} - {$task->getRecipient()}",
                'title' => "{$task->getService()} | {$task->getRecipient()}",
                'backgroundColor' => match ($isOnPremises) {
                    true => 'green',
                    false => 'cornflowerblue'
                },
                'extendedProps' => [
                    'on_premises' => $task->isOnPremises(),
                    'consultant' => $task->getConsultant()->getName(),
                    'recipient' => $task->getRecipient()->getName(),
                    'recipient_location' => $task->getRecipient()->getHeadquarters(),
                    'service' => $task->getService()->getName(),
                    'schedule_id' => $task->getSchedule()->getUuid()->toRfc4122()
                ]
            ];
        }

        return new JsonResponse($data);
    }

    public function fetch()
    {

    }

    /**
     * TODO
     *   - check enough hours free
     */
    #[Route(name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, ValidatorInterface $validator, ScheduleManagerFactory $managerFactory): Response
    {
        // TODO check permissions

        // Parse parameters
        if ($start = $request->request->get('start')) {
            $start = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $start);
            if (!$start || ($start->format('i:s') != '00:00'))
                throw new BadRequestHttpException("Invalid start=$start");
        }
        if ($end = $request->request->get('end')) {
            $end = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $end);
            if (!$end || ($end->format('i:s') != '00:00'))
                throw new BadRequestHttpException("Invalid end=$end");

        }
        $onPremises = $request->request->getBoolean('onPremises', false);

        if ($scheduleId = $request->request->get('schedule')) {
            $em->find(Schedule::class, (int)$scheduleId);
        }
        if ($contractedServiceID = $request->request->get('contractedService'))
            $contractedService = $em->find(ContractedService::class, (int)$contractedServiceID);

        // Default parameters
        if (!isset($schedule) && isset($contractedService))
            $schedule = $em->getRepository(Schedule::class)->findOneBy(['consultant' => $contractedService->getConsultant()]);

        // Validate parameters
        if (!isset($contractedService))
            throw new BadRequestHttpException("Missing contractedService");
        if (!isset($schedule) || $schedule->getConsultant() !== $contractedService->getConsultant())
            throw new BadRequestHttpException('Missing schedule or schedule::consultant !== consultant');
        if ($onPremises && $start < new \DateTime('+4days '.ScheduleManager::DAY_START))
            throw new BadRequestHttpException("On premises task must not start earlier than 4 days from today");

        $task = new Task();
        $task->setStart($start);
        $task->setEnd($end);
        $task->setOnPremises($onPremises);
        $task->setContractedService($contractedService);

        $errors = $validator->validate($task);
        if (count($errors) > 0) throw new BadRequestHttpException("Invalid task ". $errors);

        $manager = $managerFactory->createScheduleManager($schedule);

        if ($task->isOnPremises()) {
            // TODO what if any used task is in the target range? delay the task first

            // start or end within the task's start-end
            $criteria = Criteria::create()
                ->where(Criteria::expr()->orX(
                    // |$start ===== overlapping.start ===== $end|
                    // $start <= task.start < $end
                    Criteria::expr()->andX(
                        Criteria::expr()->gte('start', $start),
                        Criteria::expr()->lt('start', $end), // task.start == $end does not overlap
                    ),
                    // |$start ===== overlapping.end ===== $end|
                    // $start < task.end <= $end
                    Criteria::expr()->andX(
                        Criteria::expr()->gt('end', $start), // task.end == $start does not overlap
                        Criteria::expr()->lte('end', $end),
                    ),
                    // overlapping.start ====== |$start ---- $end| ===== overlapping.end
                    // task.start < $start && task.end > $end
                    Criteria::expr()->andX(
                        Criteria::expr()->lt('start', $start),
                        Criteria::expr()->gt('end', $end)
                    )
                ));
            /** @var Task[] $overlapping */
            $overlapping = $schedule->getTasks()->matching($criteria);

            foreach ($overlapping as $overlappingTask) {
                try {
                    $manager->reallocateTaskToSameDayAdjacentSlots($overlappingTask, Period::make($task->getEnd(), $schedule->getTo(), Precision::HOUR(), Boundaries::EXCLUDE_END()));
                } catch (NoFreeSlotsAvailableException $e) {
                    throw new BadRequestHttpException("No free slots available");
                }
            }

            /** @var Task[] $sourceTasks */
            if (!isset($use)) {
                // Use all onPremises task of this contracted_service that can be moved. Starts from tomorrow morning.
                $criteria = ScheduleRepository::createTasksOnPremisesAfterCriteria($contractedService, new \DateTime('+1day '.ScheduleManager::DAY_START));
                $sourceTasks = $schedule->getTasks()->matching($criteria);
            } else {
                $use = explode(',', $request->request->get('use'));
                $sourceTasks = array_map(fn($taskId) => $em->find(Task::class, $taskId), $use);
            }

            $neededHours = $task->getHours();
            $gotHours = 0;
            $taskHoursToRemove = new \SplObjectStorage();
            foreach ($sourceTasks as $sourceTask) {
                if (!$taskHoursToRemove->contains($sourceTask))
                    $taskHoursToRemove[$sourceTask] = 0;

                while ($gotHours < $neededHours && $taskHoursToRemove[$sourceTask] < $sourceTask->getHours()) {
                    $gotHours++;
                    $taskHoursToRemove[$sourceTask] += 1;
                }
            }

            /** @var Task[] $taskHoursToRemove */
            foreach ($taskHoursToRemove as $removeTask) {
                if ($removeTask->getHours() === $taskHoursToRemove[$removeTask])
                    $manager->removeTask($removeTask);
                else {
                    $newPeriod = Period::make($removeTask->getStart(), $removeTask->getEnd()->modify("-{$taskHoursToRemove[$removeTask]} hours"), Precision::HOUR(), Boundaries::EXCLUDE_END());
                    $manager->moveTask($removeTask, $newPeriod);
                }
            }

            if ($neededHours > $gotHours)
                throw new ConflictHttpException("Not enough free hours: available=$gotHours needed=$neededHours");
        }

        // TODO if target slots are allocated, then throw. Notice that if onPremises, then the above block should have moved the tasks.

        $manager->addTask($task);

        // Validate consultant Schedule
//        if (count($errors = $validator->validate($manager, null, ['Default', 'consultant', 'generation'])) > 0)
//            throw new \Exception("Invalid schedule for consultant {$consultant}:". $errors);

        $em->flush();




        return new JsonResponse($this->jsonTask($task), Response::HTTP_CREATED);
    }

    public function update()
    {

    }

    public function delete()
    {

    }

    /**
     *
     * Delays at task farther in the future i.e. > today.
     * On premises task must be delay by at least 4 days.
     *
     * TODO:
     *  - task must not be alrady exectued/performed: do this by simply applying the transition to 'scheduled' and see if it throws
     *
     * @param EntityManagerInterface $em
     * @param ValidatorInterface $validator
     * @param ScheduleManagerFactory $scheduleManagerFactory
     * @return Response
     */
    #[Route('/{taskId<\d+>}/delay', name: 'delay', methods: ['POST'])]
    public function delay(int $taskId, Request $request, EntityManagerInterface $em, ValidatorInterface $validator, ScheduleManagerFactory $scheduleManagerFactory): JsonResponse
    {
        // TODO check permissions
        $task = $em->find(Task::class, $taskId);
        if (!$task)
            throw $this->createNotFoundException("Task not found");

        // Parse parameters
        if ($after = $request->request->get('after')) {
            $after = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $after);
            if (false === $after)
                throw new BadRequestHttpException();
        }
        if ($before = $request->request->get('before')) {
            $before = \DateTimeImmutable::createFromFormat(DATE_RFC3339, $before);
            if (false === $before)
                throw new BadRequestHttpException();
        }

        $schedule = $task->getSchedule();
        $manager = $scheduleManagerFactory->createScheduleManager($schedule);

        // Set default parameters
        if (!$before) {
            $before = $manager->getTo();
        }
        if (!$after) {
            $after = new \DateTimeImmutable('+1day '.ScheduleManager::DAY_START);

            if ($task->isOnPremises())
                $after = $after->modify('+3days');

            if ($task->getEnd()->modify(ScheduleManager::DAY_END) >= $after)
                $after = $task->getEnd()->modify('+1day '. ScheduleManager::DAY_START);
        }

        // Validate parameters
        if ($after < $task->getEnd())
            throw new BadRequestHttpException("after={$after->format(DATE_ATOM)} must be after task end={$task->getEnd()->format(DATE_ATOM)})");
        if ($task->isOnPremises() && $after < (new \DateTime('+4days '.ScheduleManager::DAY_START)))
            throw new BadRequestHttpException("On premises tasks must be delayed by at least 4 days.");

        $period = $manager->createFittedPeriodFromBoundaries($after, $before);

        try {
            $manager->reallocateTaskToSameDayAdjacentSlots($task, $period);
        } catch (NoFreeSlotsAvailableException $e) {
            throw new BadRequestHttpException("No free slots available");
        }

        $changeset = $manager->getScheduleChangeset();

        $em->persist($changeset);

        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function jsonTask(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'start' => $task->getStart()->format(DATE_RFC3339),
            'end' => $task->getEnd()->format(DATE_RFC3339),
            'onpremises' => $task->isOnPremises(),
            'schedule' => $task->getSchedule()->getId(),
            'contracted_service' => $task->getContractedService()->getId()
        ];
    }
}
