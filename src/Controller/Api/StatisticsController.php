<?php


namespace App\Controller\Api;

use App\Entity\ContractedService;
use App\Entity\Service;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/statistics', name: 'statistics_')]
class StatisticsController
{
    #[Route('/', name: 'all')]
    public function all(EntityManagerInterface $entityManager, Connection $defaultConnection): Response
    {
        $contractedServiceTable = $entityManager->getClassMetadata(ContractedService::class)->getTableName();
        $serviceTableName = $entityManager->getClassMetadata(Service::class)->getTableName();

        $sql = <<<SQL
(SELECT null as consultant, cs.service_id as service, SUM(s.hours) as hours
FROM {$contractedServiceTable} cs LEFT JOIN {$serviceTableName} s ON cs.service_id=s.name
GROUP BY cs.service_id
ORDER BY cs.service_id)

UNION

(SELECT cs.consultant_id as consultant, cs.service_id as service, SUM(s.hours) as hours
FROM {$contractedServiceTable} cs LEFT JOIN {$serviceTableName} s ON cs.service_id=s.name
GROUP BY cs.consultant_id, cs.service_id WITH ROLLUP
ORDER BY cs.consultant_id, cs.service_id)
SQL;

        $data = [];
        foreach ($defaultConnection->executeQuery($sql)->iterateAssociative() as [
            'consultant' => $consultantName,
            'service' => $serviceName,
            'hours' => $hours,
        ]) {
            if ($consultantName === null)
                $consultantName = 'TOTALE';

            if (!isset($data[$consultantName]))
                $data[$consultantName] = [];

            if ($serviceName === null)
                $serviceName = 'TOTALE'; //

            $data[$consultantName][$serviceName] = intval($hours);
        }


        return new JsonResponse($data);
    }
}
