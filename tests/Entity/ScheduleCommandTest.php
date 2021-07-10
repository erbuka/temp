<?php


namespace App\Tests\Entity;


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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ScheduleCommandTest extends KernelTestCase
{
    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testAddTaskCommand(Schedule $schedule, ContractedService $cs)
    {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);

        $cmd = new AddTaskCommand($schedule, $t);
        $cmd->execute();

        $this->assertCount(1, $schedule->getTasks());
        $this->assertTrue($schedule->getTasks()->contains($t));

        $cmd->undo();

        $this->assertCount(0, $schedule->getTasks());
        $this->assertFalse($schedule->getTasks()->contains($t), "Schedule should not contain the task");
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testRemoveTaskCommand(Schedule $schedule, ContractedService $cs)
    {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($from->setTime(10, 0))
            ->setEnd($from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);
        $schedule->addTask($t);

        $cmd = new RemoveTaskCommand($schedule, $t);
        $cmd->execute();

        $this->assertCount(0, $schedule->getTasks());
        $this->assertFalse($schedule->getTasks()->contains($t), "Schedule should not contain the task");

        $cmd->undo();

        $this->assertCount(1, $schedule->getTasks());
        $this->assertTrue($schedule->getTasks()->contains($t));
    }

    /**
     * @dataProvider emptyScheduleAndContractedServiceProvider
     */
    public function testMoveTaskCommand(Schedule $schedule, ContractedService $cs)
    {
        $from = $schedule->getFrom();
        $t = (new Task)
            ->setStart($oldStart = $from->setTime(10, 0))
            ->setEnd($oldEnd = $from->setTime(12, 0))
            ->setOnPremises(true)
            ->setContractedService($cs);
        $schedule->addTask($t);

        $cmd = new MoveTaskCommand($schedule, $t, start: $newStart = $t->getStart()->modify('+1 day'), end: $newEnd = $t->getEnd()->modify('+1 day +2 hours'));
        $cmd->execute();

        $this->assertEquals(4, $t->getHours(), "Task hours not updated after move");
        $this->assertEquals($newStart, $t->getStart(), "Task start not updated");
        $this->assertEquals($newEnd, $t->getEnd(), "Task end not updated");

        $cmd->undo();

        $this->assertEquals(2, $t->getHours(), "Task hours not reverted after move");
        $this->assertEquals($oldStart, $t->getStart(), "Task start not reverted");
        $this->assertEquals($oldEnd, $t->getEnd(), "Task end not reverted");
    }

    //region Providers

    public function emptyScheduleAndContractedServiceProvider() {
        $schedule = new Schedule($from = new \DateTimeImmutable('2021-07-15'), $from->modify('+12 month'));
        $cs = (new ContractedService())
            ->setContract((new Contract)->setRecipient((new Recipient())->setName('Recipient #2')))
            ->setConsultant((new Consultant())->setName('Cugusi Mario'))
            ->setService((new Service())
                ->setName('3. Zootecnica')
                ->setHours(10)
                ->setHoursOnPremises(4)
            );

        $fixtures = [
            'schedule#1' => [$schedule, $cs]
        ];

        return $fixtures;
    }

    //endregion Providers
}
