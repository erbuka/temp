<?php


namespace App\Tests\Entity;


use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\Repository\ScheduleRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScheduleTest extends KernelTestCase
{
    public function testDoctrineCriteriaOnNonPersistedEntity() {
        $schedule = new Schedule($from = new \DateTimeImmutable(), $from->modify('+1 month'));
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(10)
                ->setHoursOnPremises(4)
            );
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart($from->modify('+4 days')->setTime(8, 0))
            ->setEnd(($from->modify('+4 days')->setTime(12, 0)))
            ->setOnPremises(true));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart($from->modify('+2 days')->setTime(8, 0))
            ->setEnd(($from->modify('+2 days')->setTime(12, 0)))
            ->setOnPremises(true));
        $schedule->addTask((new Task())
            ->setContractedService($cs)
            ->setStart($from->modify('+1 days')->setTime(8, 0))
            ->setEnd(($from->modify('+1 days')->setTime(12, 0)))
            ->setOnPremises(false));

        /** @var \Doctrine\Common\Collections\ArrayCollection<Task> $tasks */
        $tasks = $schedule->getTasks()->matching(ScheduleRepository::createSortedTasksCriteria());

        $this->assertTrue($tasks->first()->getStart() < $tasks->last()->getStart(), "Doctrine doesn't sort collections that have not been persisted");
    }
}
