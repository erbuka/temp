<?php


namespace App;


use App\Entity\Task;
use Spatie\Period\Period;
use Spatie\Period\Precision;

/**
 * Represent a unitary 1 hour slot used by the scheduler.
 *
 * Each slot is indivisible and represents the smallest unit of time.
 *
 * Each slot can have at most 1 task associated with it (for now).
 *
 * @package App
 */
class Slot
{
    private Period $period;

    private \DateTimeInterface $start;

    private Task $task;

    public function __construct(\DateTimeInterface $start, \DateInterval $interval = null)
    {
        if (!$interval)
            $interval = new \DateInterval('PT1H');

        $start = \DateTimeImmutable::createFromInterface($start);
        $end = $start->add($interval);

        $this->period = Period::make($start, $end, Precision::HOUR());
        assert($start->format('Y-m-d') === $end->format('Y-m-d'), "Slot period must not cross days {$this->period->asString()}");
    }

    public function isAllocated(): bool
    {
        return isset($this->task);
    }

    public function isFree(): bool
    {
        return !$this->isAllocated();
    }

    public function assignTask(Task $task)
    {
        $this->task = $task;
    }

    public function getPeriod(): Period
    {
        return $this->period;
    }

    public function getStart(): \DateTimeInterface
    {
        return $this->period->start();
    }

    public function getEnd(): \DateTimeInterface
    {
        return $this->period->end();
    }
}
