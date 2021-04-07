<?php


namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/entity', name: 'entity_')]
class EntityController
{
    #[Route('/add', name: 'add')]
    public function add()
    {
        return new Response(__METHOD__);
    }
}