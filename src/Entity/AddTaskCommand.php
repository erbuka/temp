<?php


namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;

/**
 * Operates on the given task.
 *
 * The problem is that by operating on the individual task, it also modifies the schedule
 * by e.g. removing the task from the schedule.
 *
 * As a result, e.g. task add to the schedule
 *
 * Class TaskCommand
 * @package App
 *
 * @ORM\Entity
 */
class AddTaskCommand extends TaskCommand
{
    /**
     * DOs:
     *  - change task
     *  - modify schedule tasks to include the task
     *
     * DONTs
     *  - update the state of ScheduleManager
     *
     */
    public function execute(): void
    {
        // add task to the schedule
        $this->getSchedule()->addTask($this->getTask());
    }

    public function undo(): void
    {
        $this->getSchedule()->removeTask($this->getTask());
    }
}
