<?php


namespace App;


use App\Entity\Consultant;
use App\Entity\Schedule;
use App\Entity\Task;
use App\Repository\ScheduleRepository;
use Doctrine\Common\Collections\Collection;

/**
 * Enables working on the subset of tasks belonging to a given Consultant by decorating a Schedule.
 * The advantage is that only tasks assigned to a consultant are fetched from the database.
 * Moreover, when used by a ScheduleManager this results in optimized operations (lower memory, faster lookups, etc).
 *
 * The idea is that this is just a container for a group of tasks having the same consultant.
 * Objects are not persisted, but changes to tasks are persisted.
 *
 * Adding/removing tasks must perform the operation on the parent schedule.
 *
 * Class ConsultantSchedule
 * @package App
 */
class ConsultantSchedule extends Schedule
{
    private Schedule $schedule;
    private Consultant $consultant;

    public function __construct(Schedule $schedule, Consultant $consultant)
    {
        parent::__construct($schedule->getFrom(), $schedule->getTo());

        $this->schedule = $schedule;
        $this->consultant = $consultant;
        $this->tasks = $schedule->getTasks()->matching(ScheduleRepository::createTasksFilteredByConsultantCriteria($consultant));
    }

    public static function fromSchedule(Schedule $schedule, Consultant $consultant): static
    {
        return new static($schedule, $consultant);
    }

    public function addTask(Task $task): static
    {
        $this->schedule->addTask($task);
        $this->tasks[] = $task;

        return $this;
    }

    public function removeTask(Task $task): static
    {
        $this->schedule->removeTask($task);
        $this->tasks->removeElement($task);

        return $this;
    }

    public function getTasks(): Collection
    {
        return $this->tasks;
    }
}
