<?php


namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/calendar-tasks', name: 'calendar-tasks_')]
class EntityController
{
    #[Route('/', name: 'list')]
    public function list()
    {
        $tasks = [
            ['title' => 'API #1', 'start' => '2021-04-08T08:00:00Z', 'end' => '2021-04-01T09:00:00Z'],
            ['title' => 'API #2', 'start' => '2021-04-09T12:00:00Z', 'end' => '2021-04-02T13:00:00Z'],
            ['title' => 'API #3', 'start' => '2021-04-10T14:00:00Z', 'end' => '2021-04-08T15:00:00Z'],
        ];

        return new JsonResponse($tasks);
    }
}
