<?php


namespace App\Controller\Api;

use App\Entity\Task;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/tasks', name: 'calendar-tasks_')]
class TaskController
{
    /**
     * Returns all tasks belonging to a schedule.
     *
     * filter[schedule]: Task::scheduleId
     * filter[consultant]: Consultant::name
     *
     * @return JsonResponse
     */
    #[Route('/', name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $entityManager, Connection $defaultConnection)
    {
        $tasks = $entityManager->getRepository(Task::class)->findAll();

        $data = [];

        foreach ($tasks as $task) {
            /** @var Task $task */
            $data[] = [
                'id' => $task->getId(),
                'start' => $task->getStart()->format(DATE_ATOM),
                'end' => $task->getEnd()->format(DATE_ATOM),
                'title' => "{$task->getConsultant()->getName()} - {$task->getRecipient()->getName()}",
                'extendedProps' => [
                    'on_premises' => $task->getOnPremises(),
                    'consultant' => $task->getConsultant()->getName(),
                    'recipient' => $task->getRecipient()->getName(),
                    'schedule_id' => $task->getScheduleId()->toRfc4122()
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
