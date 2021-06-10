<?php


namespace App\Controller\Api;

use App\Entity\ContractedService;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
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
GROUP BY cs.consultant_id, cs.service_id WITH ROLLUP)
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

    /**
     * Returns (slot_hour, slot_day for each consultant's schedule.
     * This is used to create a scatter plot where each point corresponds to a (consultant, slot_hour, slot_day#)
     */
    #[Route('/schedule', name: 'schedule')]
    public function scheduling(EntityManagerInterface $em): Response
    {
        $schedules = $em->getRepository(Schedule::class)->findAll();
        assert(count($schedules) > 0, "No schedules found");

        // TODO assumes each task occupies 1 hour slot. This won't hold true when adjacent slot aggregation is implemented.
        $data = [];

        foreach ($schedules as $schedule) {
            /** @var Schedule $schedule */
            foreach ($schedule->getTasks() as $task) {
                /** @var Task $task */
                $consultant = $task->getConsultant()->getName();

                if (!isset($data[$consultant]))
                    $data[$consultant] = [];

                // assumes task duration does not cross the day!
                assert(($h = $task->getStart()->diff($task->getEnd())->h) === 1, "Task lasts {$h}>1 hours");

                $data[$consultant][] = [
                    'hour_slot' => intval($task->getStart()->format('H')),  // 00:00 => 0, 01:00 => 1, ...
                    'day_slot' => $schedule->getFrom()->diff($task->getStart())->days,
                    'recipient' => $task->getRecipient()->getName(),
                    'service' => $task->getService()->getName(),
                ];
            }
        }

        return new JsonResponse($data);
    }
}
