<?php


namespace App\Entity;


use App\ScheduleInterface;
use Doctrine\ORM\Mapping as ORM;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;

/**
 * @ORM\Entity
 */
class MoveTaskCommand extends TaskCommand
{
    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $start;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $end;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $previousStart;

    /**
     * @ORM\Column(type="datetime_immutable")
     */
    private \DateTimeImmutable $previousEnd;

    public function __construct(Schedule $schedule, Task $task, \DateTimeInterface $start = null, \DateTimeInterface $end = null)
    {
        parent::__construct($schedule, $task);

        $this->previousStart = $task->getStart();
        $this->previousEnd = $task->getEnd();
        $this->start = \DateTimeImmutable::createFromInterface($start ?? $task->getStart());
        $this->end = \DateTimeImmutable::createFromInterface($end ?? $task->getEnd());
    }

    public function execute(): void
    {
        $task = $this->getTask();

        $task->setStart($this->start);
        $task->setEnd($this->end);
    }

    public function undo(): void
    {
        $task = $this->getTask();

        $task->setStart($this->previousStart);
        $task->setEnd($this->previousEnd);
    }

    public function getPreviousStart(): \DateTimeImmutable
    {
        return $this->previousStart;
    }

    public function getPreviousEnd(): \DateTimeImmutable
    {
        return $this->previousEnd;
    }
}
