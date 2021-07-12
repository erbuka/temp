<?php


namespace App\Controller\Api;

use App\Entity\Consultant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/consultants', name: 'consultant_')]
class ConsultantController
{
    #[Route(name:'list', methods:['GET'])]
    public function list(EntityManagerInterface $entityManager)
    {
        $consultants = $entityManager->getRepository(Consultant::class)->findAll();

        $data = [];
        foreach ($consultants as $consultant) {
            /** @var Consultant $consultant */
            $data[] = [
                'name' => $consultant->getName(),
                'title' => $consultant->getTitle(),
                'job_title' => $consultant->getJobTitle(),
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
