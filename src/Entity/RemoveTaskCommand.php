<?php


namespace App\Entity;


use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class RemoveTaskCommand extends TaskCommand
{
    public function execute(): void
    {
        // add task to the schedule
        $this->getSchedule()->removeTask($this->getTask());
    }

    public function undo(): void
    {
        $this->getSchedule()->addTask($this->getTask());
    }
}
