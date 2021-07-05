<?php


namespace App\Tests;


use App\Entity\AddTaskCommand;
use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\RemoveTaskCommand;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScheduleManagerTest extends KernelTestCase
{
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
        $manager->consolidateNonOverlappingTasksDaily();

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
        $manager->consolidateNonOverlappingTasksDaily();

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

    public function testSameDayAdjacentTasksConsolidation() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-06-18'), $from->modify('+1 week'));
        $manager = $this->getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())->setName('3. Zootecnica')
                ->setHours(34)
                ->setHoursOnPremises(24)
            );
        $cs2 = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #1')))
            ->setConsultant((new Consultant())->setName('Belelli Fiorenzo'))
            ->setService((new Service())->setName('3. Direttiva acque')
                ->setHours(34)->setHoursOnPremises(24)
            );

        $schedule->addTask($t1 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(9, 0))
            ->setEnd(($from->setTime(11, 0)))
            ->setOnPremises(true));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(11, 0))
            ->setEnd(($from->setTime(14, 0)))
            ->setOnPremises(true));
        $schedule->addTask($t3 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(14, 0))
            ->setEnd(($from->setTime(15, 0)))
            ->setOnPremises(true));

        $schedule->addTask($t5 = (new Task())
            ->setContractedService($cs2)
            ->setStart($from->setTime(12, 0))
            ->setEnd(($from->setTime(13, 0)))
            ->setOnPremises(true));
        $schedule->addTask($t6 = (new Task())
            ->setContractedService($cs2)
            ->setStart($from->setTime(13, 0))
            ->setEnd(($from->setTime(16, 0)))
            ->setOnPremises(true));

        $manager->reloadTasks();
        $manager->consolidateSameDayAdjacentTasks();

        $tasks = $schedule->getTasks();

        $this->assertCount(2, $tasks, "Tasks added by consolidation");
        $this->assertTrue($tasks->contains($t1), "Task {$t1} should not be removed in consolidation");
        $this->assertFalse($tasks->contains($t2), "Task {$t2} not consolidated");
        $this->assertFalse($tasks->contains($t3), "Task {$t3} not consolidated");
        $this->assertEquals($from->setTime(9, 0), $t1->getStart(), "Adjacent tasks of {$t1} not consolidated: wrong start");
        $this->assertEquals($from->setTime(15, 0), $t1->getEnd(), "Adjacent tasks of {$t1} not consolidated: wrong end");

        $this->assertTrue($tasks->contains($t5), "Task {$t5} should not be removed in consolidation");
        $this->assertFalse($tasks->contains($t6), "Task {$t2} not consolidated");
        $this->assertEquals($from->setTime(12, 0), $t5->getStart(), "Adjacent tasks of {$t5} not consolidated: wrong start");
        $this->assertEquals($from->setTime(16, 0), $t5->getEnd(), "Adjacent tasks of {$t5} not consolidated: wrong end");

    }

    public function testSameDayAdjacentTasksConsolidationShouldNotMergeAcrossDays() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-06-21'), $from->modify('+1 week'));
        $manager = $this->getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())->setName('3. Zootecnica')
                ->setHours(34)
                ->setHoursOnPremises(24)
            );

        $schedule->addTask($t1 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->setTime(15, 0))
            ->setEnd(($from->setTime(18, 0)))
            ->setOnPremises(false));
        $schedule->addTask($t2 = (new Task())
            ->setContractedService($cs)
            ->setStart($from->modify('+1 day')->setTime(8, 0))
            ->setEnd(($from->modify('+1 day')->setTime(12, 0)))
            ->setOnPremises(false));

        $manager->reloadTasks();
        $manager->consolidateSameDayAdjacentTasks();

        /** @var Task[] $tasks */
        $tasks = $schedule->getTasks();

        $this->assertCount(2, $tasks, "Tasks added by consolidation");
        $this->assertEquals($from->setTime(15, 0), $t1->getStart(), "Changed non adjacent tasks start hour {$t1}");
        $this->assertEquals($from->setTime(18, 0), $t1->getEnd(), "Changed non adjacent tasks end hour {$t1}");
        $this->assertEquals($from->modify('+1 day')->setTime(8, 0), $t2->getStart(), "Changed non adjacent tasks start hour {$t2}");
        $this->assertEquals($from->modify('+1 day')->setTime(12, 0), $t2->getEnd(), "Changed non adjacent tasks end hour {$t2}");

    }

    //region Commands

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testAddTask(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        // Required: empty schedule, task
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $manager->addTask($t);
        $changeset = $manager->getScheduleChangeset();
        /** @var AddTaskCommand $cmd */
        $cmd = $changeset->getCommands()->first();

        $this->assertTrue($schedule->getTasks()->contains($t), "Task not added into schedule");
//        $this->assertSame($t, current($manager->getConsultantTasks($t->getConsultant())), "::tasksByConsultant misses the task");
        $this->assertInstanceOf(AddTaskCommand::class, $cmd, "Executed command is not AddTaskCommand");
        $this->assertSame($cmd->getTask(), $t, "Schedue command's task mismatch");
        $this->assertContains($t, $manager->getConsultantTasks($t->getConsultant()), "::getConsultantTasks() out-of-sync");
        $this->assertEquals($t->getHours(), $manager->getConsultantHours($t->getConsultant()), "::getConsultantHours() out of sync");

        $cmd->undo();
        $this->assertFalse($schedule->getTasks()->contains($t), "::undo() did not remove added task");
    }


    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testRemoveTask(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        // Required: empty schedule, task
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $manager->addTask($t);
        $manager->removeTask($t);
        $changeset = $manager->getScheduleChangeset();
        /** @var AddTaskCommand $cmd */
        $cmd = $changeset->getCommands()->last();

        $this->assertFalse($schedule->getTasks()->contains($t), "Task still present in schedule");
        $this->assertInstanceOf(RemoveTaskCommand::class, $cmd, "Executed command is not RemovedTaskCommand");
        $this->assertNotContains($t, $manager->getConsultantTasks($t->getConsultant()), "::getConsultantTasks() out-of-sync");
        $this->assertEquals(0, $manager->getConsultantHours($t->getConsultant()), "::getConsultantHours() out of sync");
        $this->assertEquals(0, $manager->getConsultantHoursOnPremises($t->getConsultant()), "::getConsultantHoursOnPremises() out of sync");

        $cmd->undo();
        $this->assertTrue($schedule->getTasks()->contains($t), "::undo() did not add the removed task");
    }

    //endregion Commands

    //region Providers

    public function emptyScheduleAndContractedServiceProvider() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-07-15'), $from->modify('+12 month'));
        $manager = static::getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(10)
                ->setHoursOnPremises(4)
            );

        $fixtures = [
            'schedule#1' => [$manager, $schedule, $cs]
        ];

        return $fixtures;
    }

    //endregion Providers
}
