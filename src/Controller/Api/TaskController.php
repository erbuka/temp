<?php


namespace App\Controller\Api;

use App\Entity\Consultant;
use App\Entity\Schedule;
use App\Entity\Task;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

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
        if (isset($filter['consultant'])) {
            $filter['consultant'] = $em->getRepository(Consultant::class)->find($filter['consultant']);
            if (!$filter['consultant']) $this->createNotFoundException("Invalid consultant");
        }

        // Defaults
        if (!isset($filter['schedule'])) {
            // Fetch the latest schedule
            $filter['schedule'] = current(array_reverse($em->getRepository(Schedule::class)->findAll()));
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
            $data[] = [
                'id' => $task->getId(),
                'start' => $task->getStart()->format(DATE_ATOM),
                'end' => $task->getEnd()->format(DATE_ATOM),
                'title' => "{$task->getService()} - {$task->getRecipient()->getHeadquarters()} - {$task->getRecipient()}",
                'extendedProps' => [
                    'on_premises' => $task->getOnPremises(),
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

    public function create()
    {

    }

    public function update()
    {

    }

    public function delete()
    {

    }
}
