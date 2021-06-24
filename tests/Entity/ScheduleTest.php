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
use App\ScheduleManager;
use App\ScheduleManagerFactory;
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
        $tasks = $schedule->getTasks()->matching(ScheduleRepository::createTasksSortedByStartCriteria());

        $this->assertTrue($tasks->first()->getStart() < $tasks->last()->getStart(), "Doctrine doesn't sort collections that have not been persisted");
    }

    public function testTasksConsolidationShouldNotConsolidate() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-06-18'), $from->modify('+1 week'));
        $manager = $this->getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(10)
                ->setHoursOnPremises(4)
            );

        $schedule->addTask($t1 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(9, 0))
            ->setEnd(($from->setTime(11, 0)))
            ->setOnPremises(true));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(14, 0))
            ->setEnd(($from->setTime(18, 0)))
            ->setOnPremises(false));

        $manager->reloadTasks();
        $schedule->consolidateNonOverlappingTasksDaily();

        /** @var Task[] $tasks */
        $tasks = $schedule->getTasks();

        $this->assertCount(2, $tasks, "Tasks added by consolidation");
        $this->assertSame($t1, $tasks[0], "Task changed by consolidation");
        $this->assertSame($t1->getStart(), $tasks[0]->getStart(), "Task start changed by consolidation");
        $this->assertSame($t1->getEnd(), $tasks[0]->getEnd(), "Task end changed by consolidation");
        $this->assertSame($t2, $tasks[1]);
        $this->assertSame($t2->getStart(), $tasks[1]->getStart(), "Task start changed by consolidation");
        $this->assertSame($t2->getEnd(), $tasks[1]->getEnd(), "Task end changed by consolidation");
    }

    public function testTasksConsolidation() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-06-18'), $from->modify('+1 week'));
        $manager = $this->getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(34)
                ->setHoursOnPremises(24)
            );

        $schedule->addTask($t1 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(9, 0))
            ->setEnd(($from->setTime(11, 0)))
            ->setOnPremises(true));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(13, 0))
            ->setEnd(($from->setTime(14, 0)))
            ->setOnPremises(false));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(14, 0))
            ->setEnd(($from->setTime(18, 0)))
            ->setOnPremises(true));

        $manager->reloadTasks();
        $schedule->consolidateNonOverlappingTasksDaily();

        /** @var Task[] $tasks */
        $tasks = $schedule->getTasks();

        $this->assertCount(2, $tasks, "Tasks added by consolidation");
        $this->assertEquals($from->setTime(8, 0), $tasks[0]->getStart(), "{$t1} not relocated at 8:00");
        $this->assertEquals($from->setTime(14, 0), $tasks[0]->getEnd(), "{$t1} not expanded to 14:00");
        $this->assertTrue($tasks[0]->isOnPremises(), "Relocated {$t1} not on-premises");
        $this->assertEquals($from->setTime(14, 0), $tasks[1]->getStart(), "{$t2} not relocated at 14:00");
        $this->assertEquals($from->setTime(15, 0), $tasks[1]->getEnd(), "{$t2} not expanded to 15:00");
        $this->assertFalse($tasks[1]->isOnPremises(), "Relocated {$t2} is on-premises");
    }
}
