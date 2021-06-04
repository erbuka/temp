<?php


namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/calendar-tasks', name: 'calendar-tasks_')]
class CalendarTasksController
{
    #[Route('/', name: 'list')]
    public function list()
    {
        $tasks = [
            [
                'start' => '2021-04-08T08:00:00Z',
                'end' => '2021-04-01T09:00:00Z',
                'title' => 'API #1',
                'extendedProps' => [
                    'recipient' => 'company 5',
                    'consultant' => 'Ettore DN',
                    'activity' => 'activity$1',
                    'method' => 'onpremise',
                ]
            ],
            ['title' => 'API #2', 'start' => '2021-04-09T12:00:00Z', 'end' => '2021-04-02T13:00:00Z'],
            ['title' => 'API #3', 'start' => '2021-04-10T14:00:00Z', 'end' => '2021-04-08T15:00:00Z'],
        ];

        return new JsonResponse($tasks);
    }
}
