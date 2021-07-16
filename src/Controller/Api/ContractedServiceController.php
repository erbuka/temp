<?php


namespace App\Controller\Api;


use App\Entity\Consultant;
use App\Entity\ContractedService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
#[Route('/contracted-services', name: 'contracted-services_')]
class ContractedServiceController extends AbstractController
{
    #[Route(name: 'list', methods: ['GET'])]
    public function list(EntityManagerInterface $em, Connection $defaultConnection, Request $request): JSONResponse
    {
        /** @var Consultant $user */
        $user = $this->getUser();
        assert($user instanceof Consultant);

        $cs = $em->getRepository(ContractedService::class)->findBy(['consultant' => $user]);
        if (empty($cs))
            $this->createNotFoundException();

        $data = [];
        foreach ($cs as $c) {
            /** @var ContractedService $c */
            $data[] = $c->toArray();
        }

        return new JsonResponse($data);
    }
}
