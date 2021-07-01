<?php


namespace App;


use App\Entity\Consultant;
use App\Entity\ContractedService;
use App\Entity\Recipient;
use App\Entity\Service;
use App\Entity\Task;
use JetBrains\PhpStorm\Pure;
use Spatie\Period\Boundaries;
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
class Slot implements \Stringable
{
    private Period $period;
    private \SplObjectStorage $tasks;
    private int $index;

    public function __construct(int $index, \DateTimeInterface $start, \DateInterval $interval = null)
    {
        if (!$interval)
            $interval = new \DateInterval('PT1H');

        $this->index = $index;
        $start = \DateTimeImmutable::createFromInterface($start);
        $end = $start->add($interval);

        $this->period = Period::make($start, $end, Precision::HOUR(), Boundaries::EXCLUDE_END());
        $this->tasks = new \SplObjectStorage();

        assert($this->period->length() === 1, "Created period length different than 1 hour: {$this->period->length()}");
        assert($start->format('Y-m-d') === $end->format('Y-m-d'), "Slot period must not cross days {$this->period->asString()}");
    }

    #[Pure] public function isAllocated(): bool
    {
        return !$this->isFree();
    }

    #[Pure] public function isFree(): bool
    {
        return count($this->tasks) === 0;
    }

    public function addTask(Task $task)
    {
        $this->tasks->attach($task);
    }

    public function removeTask(Task $task)
    {
        $this->tasks->detach($task);
    }

    public function containsTask(Task $task): bool
    {
        return $this->tasks->contains($task);
    }

    public function getTasks(): array
    {
        return iterator_to_array($this->tasks);
    }

    #[Pure] public function getPeriod(): Period
    {
        return $this->period;
    }

    #[Pure] public function getStart(): \DateTimeInterface
    {
        return $this->period->start();
    }

    public function isAllocatedToConsultant(Consultant $consultant): bool
    {
        foreach ($this->tasks as $task) {
            /** @var Task $task */
            if ($task->getConsultant() === $consultant)
                return true;
        }

        return false;
    }

    public function isAllocatedToContractedService(ContractedService $cs): bool
    {
        foreach ($this->tasks as $task) {
            /** @var Task $task */
            if ($task->getContractedService() === $cs)
                return true;
        }

        return false;
    }

    public function isAllocatedOnPremisesToContractedService(ContractedService $cs): bool
    {
        foreach ($this->tasks as $task) {
            /** @var Task $task */
            if ($task->getContractedService() === $cs && $task->isOnPremises())
                return true;
        }

        return false;
    }

    public function empty(): void
    {
        $this->tasks->removeAll($this->tasks);
    }


    /**
     * Returns the *excluded* end of the period.
     * This is important because individual Tasks will be stored using the excluded end as their end time
     * e.g. Task[2021-10-23T08:00:00 - 2021-10-23T09:00:00] is meant to end just before 9am (08:59:59.9999999).
     * @return \DateTimeInterface
     */
    #[Pure] public function getEnd(): \DateTimeInterface
    {
        return $this->period->end();
    }

    public function getIndex(): int
    {
        return $this->index;
    }

    public function __toString()
    {
        return "{$this->getIndex()}:{$this->getPeriod()->asString()}";
    }
}
