<?php


namespace App\Tests;


use App\Entity\AddTaskCommand;
use App\Entity\Consultant;
use App\Entity\Contract;
use App\Entity\ContractedService;
use App\Entity\MoveTaskCommand;
use App\Entity\Recipient;
use App\Entity\RemoveTaskCommand;
use App\Entity\Schedule;
use App\Entity\Service;
use App\Entity\Task;
use App\ScheduleManager;
use App\ScheduleManagerFactory;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScheduleManagerTest extends KernelTestCase
{
    public function testSlotGenerationShouldRespectHours() {
        $schedule = new Schedule(
            $from = \DateTimeImmutable::createFromFormat(DATE_RFC3339, '2021-07-12T09:30:00Z'),
            $to = \DateTimeImmutable::createFromFormat(DATE_RFC3339, '2021-07-14T12:20:00Z')
        );
        $manager = static::getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);

        $this->assertEquals($from->modify('10:00:00'), $manager->getFrom(), "Start date does not account for hours");
        $this->assertEquals($to->modify('12:00:00'), $manager->getTo(), "End date does not account for hours");
    }

    //region DateTime utils

    public function testCeilDateTimeToSlotsInterval() {
        $d = \DateTimeImmutable::createFromFormat(DATE_RFC3339, '2021-07-04T09:12:34Z');
        $dc = $d->setTime(10 ,0, 0);

        $this->assertEquals($dc, ScheduleManager::ceilDateTimeToSlots($d), "Should ceil to next hour");
        $this->assertEquals($dc, ScheduleManager::ceilDateTimeToSlots($dc), "Should not ceil o'clock time");
    }

    public function testFloorDateTimeToSlotsInterval() {
        $d = \DateTimeImmutable::createFromFormat(DATE_RFC3339, '2021-07-04T09:12:34Z');
        $df = $d->setTime(9 ,0, 0);

        $this->assertEquals($df, ScheduleManager::floorDateTimeToSlotsInterval($d), "Should floor to beginning of the current hour");
        $this->assertEquals($df, ScheduleManager::ceilDateTimeToSlots($df), "Should not floor o'clock time");
    }

    /**
     * @dataProvider emptyScheduleProvider
     */
    public function testCreateFittedPeriodFromBoundaries() {
        $schedule = new Schedule(
            $from = \DateTimeImmutable::createFromFormat(DATE_RFC3339, '2021-07-12T09:30:00Z'),
            $to = \DateTimeImmutable::createFromFormat(DATE_RFC3339, '2021-07-14T12:20:00Z')
        );
        $manager = static::getContainer()->get(ScheduleManagerFactory::class)->createScheduleManager($schedule);

        $p1 = $manager->createFittedPeriodFromBoundaries(
            $p1s = $from->modify('-2hours'),
            $p1e = $to->modify('+1hours')
        );

        $this->assertEquals($manager->getFrom(), $p1->includedStart(), "Start not fitted to schedule boundaries");
        $this->assertTrue($p1->isEndExcluded(), "Period end is not excluded");
        $this->assertEquals($manager->getTo(), $p1->end(), "End not fitted to schedule boundaries");
        $this->assertTrue($p1->precision()->equals(Precision::HOUR()), "Period precision must be the hour");

        $p2 = $manager->createFittedPeriodFromBoundaries(
            $p2s = $from->modify('+6hours 10:00:00'),
            $p2e = $to->modify('-1day 12:00:00')
        );

        $this->assertTrue($p1->isEndExcluded(), "Period end not excluded");
        $this->assertEquals($p2s, $p2->start(), "Period start mismatch");
        $this->assertEquals($p2e, $p2->end(), "Period end mismatch");

        $p3 = $manager->createFittedPeriodFromBoundaries(
            $p3s = $from,
            $p3e = $to,
        );

        $this->assertTrue($p3->isEndExcluded(), "Period end not excluded");
        $this->assertEquals($manager->getFrom(), $p3->start(), "Period start mismatch");
        $this->assertEquals($manager->getTo(), $p3->end(), "Period end mismatch");

        $p = $manager->createFittedPeriodFromBoundaries(
            $ps = $manager->getFrom(),
            $pe = $manager->getTo(),
        );

        $this->assertTrue($p->isEndExcluded(), "Period end not excluded");
        $this->assertEquals($manager->getFrom(), $p->start(), "Period start mismatch");
        $this->assertEquals($manager->getTo(), $p->end(), "Period end mismatch");
    }


    //endregion DateTime utils

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

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testReallocateToSameDayAdjacentSlots(ScheduleManager $manager, Schedule $schedule, ContractedService $contractedService)
    {
        $from = $schedule->getFrom();
        $manager->addTask($t0 = (new Task)
            ->setContractedService($contractedService)
            ->setStart($from->modify('10:00'))
            ->setEnd($from->modify('18:00'))
            ->setOnPremises(true));
        $manager->addTask($t1 = (new Task)
            ->setContractedService($contractedService)
            ->setStart($from->modify('+1day 10:00'))
            ->setEnd($from->modify('+1day 12:00'))
            ->setOnPremises(true));
        $manager->addTask($t2 = (new Task)
            ->setContractedService($contractedService)
            ->setStart($from->modify('+2day 08:00'))
            ->setEnd($from->modify('+2day 16:00'))
            ->setOnPremises(true));

        $period = Period::make($from->modify('+2day 08:00'), $from->modify('+2day 18:00'), Precision::HOUR(), Boundaries::EXCLUDE_END());
        $manager->reallocateTaskToSameDayAdjacentSlots($t1, $period);

        $this->assertEquals($from->modify('+2day 16:00'), $t1->getStart(), "Task start not reallocated forwards");
        $this->assertEquals($from->modify('+2day 18:00'), $t1->getEnd(), "Task end not reallocated forwards");

        $periodBackwards = Period::make($from->modify('08:00'), $from->modify('18:00'), Precision::HOUR(), Boundaries::EXCLUDE_END());
        $manager->reallocateTaskToSameDayAdjacentSlots($t1, $periodBackwards);

        $this->assertEquals($from->modify('08:00'), $t1->getStart(), "Task start not reallocated backwards");
        $this->assertEquals($from->modify('10:00'), $t1->getEnd(), "Task end not reallocated backwards");
    }

//    public function testOnPremisesTaskScheduling(ScheduleManager $manager, Schedule $schedule, ContractedService $contractedService) {
//        $this->markTestIncomplete();
//
//        $from = $schedule->getFrom();
//
//        // Select originating tasks
//        $to1 = $to2 = $to3 = $to4;
//
//        // while I cumulated enough hours, remove and shrink selected tasks starting from the beginning
//
//        // -> $toRemove = [...]
//        // -> $shrunk = [..]
//
//
//
//        $t0 = (new Task)
//            ->setContractedService($contractedService)
//            ->setStart($from->modify('10:00'))
//            ->setEnd($from->modify('18:00'))
//            ->setOnPremises(true);
//
//        // 1. Get overlapping tasks from schedule
//
//        // Reschedule
//        $manager->reallocateTaskToSameDayAdjacentSlots($t_ovrelap, $period);
//        $manager->reallocateTaskToSameDayAdjacentSlots($t_ovrelap, $period);
//
//        // Assert target slots are now free;
//
//
//
//    }

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

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testMoveTaskNotBelongingToScheduleException(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $this->expectExceptionMessageMatches('/task .+ does not belong to schedule/i');
        $manager->moveTask($t, Period::make($from, $from->modify('+1 hour'), Precision::HOUR(), Boundaries::EXCLUDE_END()));
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testMoveTaskPeriodOutsideSchedule(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $this->expectExceptionMessageMatches('/Period .+ is outside schedule .+/i');
        $manager->addTask($t);
        $manager->moveTask($t, Period::make($from->setTime(6, 0), $t->getEnd(), Precision::HOUR(), Boundaries::EXCLUDE_END()));
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testMoveTaskInvalidPeriodPrecision(ScheduleManager $manager, Schedule $schedule, ContractedService $cs)
    {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $this->expectExceptionMessageMatches('/Period precision .+ does not match .+/i');
        $manager->addTask($t);
        $manager->moveTask($t, Period::make($from->setTime(6, 0), $t->getEnd(), Precision::MINUTE(), Boundaries::EXCLUDE_END()));
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testMoveTaskToAllocatedSlotsThrows(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);
        $t2 = (new Task)
            ->setStart($from->setTime(13, 0))
            ->setEnd($from->setTime(18, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $manager->addTask($t);
        $manager->addTask($t2);

        $this->expectExceptionMessageMatches('/Refusing to move task .+ to period .+ because the slots are not free/i');
        $manager->moveTask($t, Period::make($from->setTime(10, 0), $from->setTime(16, 0), Precision::HOUR(), Boundaries::EXCLUDE_END()));
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testMoveTask(ScheduleManager $manager, Schedule $schedule, ContractedService $cs) {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $manager->addTask($t);
        $manager->moveTask($t, $tp = Period::make(
            $from->modify('+1 day')->setTime(12, 0),
            $from->modify('+1 day')->setTime(18, 0), Precision::HOUR(), Boundaries::EXCLUDE_END()
        ));
        $changeset = $manager->getScheduleChangeset();
        /** @var MoveTaskCommand $cmd */
        $cmd = $changeset->getCommands()->last();

        $this->assertCount(1, $manager->getConsultantTasks($t->getConsultant()), "Consultant should have only 1 task after move");
        $this->assertEquals($t->getHours(), $manager->getConsultantHours($t->getConsultant()), "Schedule total hours mismatch");
        $this->assertInstanceOf(MoveTaskCommand::class, $cmd, "Executed command is not MoveTaskCommand");
        $this->assertSame($cmd->getTask(), $t, "Schedule command's task mismatch");
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testResizeTask(ScheduleManager $manager, Schedule $schedule, ContractedService $cs)
    {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $manager->addTask($t);
        $manager->moveTask($t, $tp = Period::make($t->getStart()->setTime(8, 0), $t->getEnd(), Precision::HOUR(), Boundaries::EXCLUDE_END()));

        $this->assertCount(1, $manager->getConsultantTasks($t->getConsultant()), "Consultant should have only 1 task after move");
        $this->assertEquals($t->getHours(), $manager->getConsultantHours($t->getConsultant()), "Schedule total hours mismatch");
    }

    //endregion Commands

    //region Providers

    public function emptyScheduleProvider() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-7-15T08:00:00Z'), $from->modify('+1year'));
        $manager = new ScheduleManager($schedule);

        $fixtures = [
            'schedule#1' => [$manager, $schedule]
        ];
        return $fixtures;
    }

    public function emptyScheduleAndContractedServiceProvider() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-07-12'), $from->modify('+12 month'));
        $manager = new ScheduleManager($schedule);
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
