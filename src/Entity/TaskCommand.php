<?php


namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
abstract class TaskCommand extends ScheduleCommand
{
    //region Persisted fields

    /**
     * The task is not expected to be permanently deleted, only filtered by Doctrine.
     *
     * @ORM\ManyToOne(targetEntity=Task::class)
     * @ORM\JoinColumn(nullable=false, onDelete="CASCADE")
     */
    private Task $task;

    //endregion Persisted fields

    public function __construct(Schedule $schedule, Task $task)
    {
        parent::__construct($schedule);

        $this->task = $task;
    }

    public function getTask(): Task
    {
        return $this->task;
    }
}
