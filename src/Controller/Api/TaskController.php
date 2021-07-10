<?php


namespace App\Controller\Api;

use App\ConsultantSchedule;
use App\Entity\Consultant;
use App\Entity\Schedule;
use App\Entity\Task;
use App\NoFreeSlotsAvailableException;
use App\ScheduleManagerFactory;
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
    #[Route('/', name: 'list', methods: ['GET'])]
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

    #[Route('/test', name: 'create', methods: ['GET'])]
    public function create(EntityManagerInterface $entityManager, ScheduleManagerFactory $managerFactory): Response
    {

        $task = new Task();

        return new JsonResponse($this->jsonTask($task), Response::HTTP_CREATED);
    }

    public function update()
    {

    }

    public function delete()
    {

    }

    #[Route('/test', name: 'test', methods: ['GET'])]
    public function test(EntityManagerInterface $entityManager, ValidatorInterface $validator, ScheduleManagerFactory $scheduleManagerFactory): Response
    {
//        $entityManager->getFilters()->disable('softdeleteable');
        $schedule = current($entityManager->getRepository(Schedule::class)->findAll());
        assert($schedule !== null);


        // Actions on the individual task
        $task = $schedule->getTasks()->first();

        $entityManager->remove($task);
        $entityManager->flush();

        return new Response('ok');
    }

    /**
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
                throw new BadRequestException();
        }

        $schedule = $task->getSchedule();
        $manager = $scheduleManagerFactory->createScheduleManager($schedule);

        // Set default parameters
        if (!$before) {
            $before = $manager->getTo();
        }
        if (!$after) {
            $after = $task->getEnd()->modify('+4days 08:00');
        }

        // Validate parameters
        if ($after < $task->getEnd())
            throw new BadRequestHttpException("after={$after->format(DATE_ATOM)} must be after task end={$task->getEnd()->format(DATE_ATOM)})");
        if ($after < $task->getEnd()->modify('+4days 08:00'))
            throw new BadRequestHttpException("Tasks must be delayed by at least 4 days.");

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
