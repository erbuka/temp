<?php


namespace App;


use App\Entity\Consultant;
use App\Entity\Schedule;
use App\Entity\Task;
use App\Repository\ScheduleRepository;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use App\Validator\Constraints as AppAssert;

class ScheduleManager
{
    const DATE_NOTIME = 'Y-m-d';
    const DATE_HOURHASH = 'YmdH';
    const DATE_DAYHASH = 'Ymd';

    private Schedule $schedule;
    private \SplFixedArray $slots; // NOTA BENE: this is shared (by ref) with Schedule.slots
    /** @var array<string, Slot> e.g. '2021020318' => Slot */
    private array $slotsByDayHours;
    /** @var array<string, Slot[]> e.g. '20210203' => Slot */
    private array $slotsByDay;
    private Period $period;
    private \SplObjectStorage $tasksByConsultant;
    private \SplObjectStorage $consultantHours;
    private \SplObjectStorage $consultantHoursOnPremises;

    public function __construct(Schedule $schedule)
    {
        $this->schedule = $schedule;
        $schedule->setManager($this);

        $this->period = Period::make($schedule->getFrom(), $schedule->getTo(), Precision::DAY(), Boundaries::EXCLUDE_END());

        $this->generateSlots();
        $this->reloadTasks();
    }

    public function getSchedule(): Schedule
    {
        return $this->schedule;
    }

    private function generateSlots()
    {
        assert(!isset($this->slots) && !isset($this->schedule->slots), "Refusing to overwrite existing slots in Schedule");

        $eligibleDays = [];
        $slotsMap = [];
        $byDay = [];

        foreach ($this->period as $dayHash) {
            /** @var \DateTimeImmutable $dayHash */

            if (AppAssert\NotItalianHolidayValidator::isItalianHoliday($dayHash, includePrefestivi: true)) {
//                $this->output->writeln(sprintf('- %s skipped because it is an holiday', $date->format(self::DATE_NOTIME)));
                continue;
            }

            if (in_array($weekday = $dayHash->format('w'), [6,0])) {
//                $this->output->writeln(sprintf('- %2$s skipped because it is %1$s', $weekday == 6 ? 'Saturday' : 'Sunday', $date->format(self::DATE_NOTIME)));
                continue;
            }

            $dayStart = \DateTime::createFromImmutable($dayHash);
            $dayStart->setTime(8, 0);
            $dayEnd = (clone $dayStart)->setTime(18, 0);

            $businessHours = Period::make($dayStart, $dayEnd, Precision::HOUR(), Boundaries::EXCLUDE_END());
            foreach ($businessHours as $hour) {
                /** @var \DateTimeImmutable $hour */
                $slot = new Slot(count($eligibleDays), $hour);
                $hash = $slot->getStart()->format(static::DATE_HOURHASH);
                $dayHash = $slot->getStart()->format(static::DATE_DAYHASH);
                assert(!isset($slotsMap[$hash]), "Multiple slots have the same hash {$hash}");

                $slotsMap[$hash] = $slot;
                $eligibleDays[] = $slot;

                if (!isset($byDay[$dayHash])) $byDay[$dayHash] = [];
                array_push($byDay[$dayHash], $slot);

                assert($slot->getIndex() === array_search($slot, $eligibleDays, strict: true));
            }
        }

        $this->setSlots(\SplFixedArray::fromArray($eligibleDays));
        $this->slotsByDayHours = $this->schedule->dayHourSlotMap = $slotsMap;
        $this->slotsByDay = $byDay;
    }

    private function setSlots(\SplFixedArray $slots): void
    {
        $this->slots = $this->schedule->slots = $slots;
    }

    /**
     * N.B. must not remove tasks!
     */
    private function clearSlots(): void
    {
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $slot->empty();
        }
    }

    /**
     * This is called by the factory to ensure the manager is in sync with the actual schedule tasks.
     * Notice that nothing prevents bogus code to directly add tasks to a schedule without using the manager.
     *
     * In general, this method is used by the factory to partially reset the manager under the assumption
     * that variants (tasks, ...) have changed between ::createScheduleManager() invocations.
     *
     * N.B. Managers are cached to avoid recreating the slots which are based on the Schedule *invariant* 'from' and 'to' properties.
     */
    public function reloadTasks()
    {
        // Empty all slots
        if (!isset($this->slots))
            $this->generateSlots();
        else
            $this->clearSlots();

        $this->tasksByConsultant = new \SplObjectStorage();
        $this->consultantHours = new \SplObjectStorage();
        $this->consultantHoursOnPremises = new \SplObjectStorage();

        // Load tasks
        $tasks = $this->schedule->getTasks()->matching(ScheduleRepository::createTasksSortedByStartCriteria());
        foreach ($tasks as $task) {
            /** @var Task $task */
            $this->loadTaskIntoSlots($task);
        }
    }

    public function getTasksByConsultant(): \SplObjectStorage
    {
        return clone $this->tasksByConsultant;
    }

    /**
     * TODO remove task from other slots, requires taskToSlotsMap
     * @param Task $task
     */
    protected function loadTaskIntoSlots(Task $task)
    {
        // A task is considered to belong to a slot iff its starting
        $period = Period::make($task->getStart(), $task->getEnd(), Precision::HOUR(), Boundaries::EXCLUDE_END());

        foreach ($period as $hour) {
            $key = $hour->format(static::DATE_HOURHASH);

            if (!isset($this->slotsByDayHours[$key]))
                throw new \RuntimeException("Task {$period->asString()} is outside this schedule boundaries {$this->period->asString()}");

            assert($this->slotsByDayHours[$key] instanceof Slot, "Map does not return a slot");
            $this->slotsByDayHours[$key]->addTask($task);
        }

        $consultant = $task->getConsultant();
        if (!isset($this->tasksByConsultant[$consultant]))
            $this->tasksByConsultant[$consultant] = [$task];
        else
            $this->tasksByConsultant[$consultant] = [...$this->tasksByConsultant[$consultant], $task];

        if (!isset($this->consultantHours[$consultant])) {
            $this->consultantHours[$consultant] = $task->getHours();
        } else
            $this->consultantHours[$consultant] += $task->getHours();

        if ($task->isOnPremises()) {
            if (!isset($this->consultantHoursOnPremises[$consultant])) {
                $this->consultantHoursOnPremises[$consultant] = $task->getHours();
            } else
                $this->consultantHoursOnPremises[$consultant] += $task->getHours();
        }
    }

    public function addTask(Task $task)
    {
        $this->schedule->addTask($task);
        $this->loadTaskIntoSlots($task);

    }

    public function getStats(): string
    {
        $allocatedSlots = 0;
        $freeSlots = 0;
//        $slotsCount = $this->slots->getSize(); // ::count() and count() are equivalent to ::getSize()
        $allocatedTasks = new \SplObjectStorage(); // Task => count of slots in which the task is included
        $taskHours = 0;

        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            if ($slot->isAllocated())
                $allocatedSlots++;
            else
                $freeSlots++;

            foreach ($slot->getTasks() as $task) {
                /** @var Task $task */
                if (!$allocatedTasks->contains($allocatedTasks))
                    $allocatedTasks->attach($task, 1);
                else
                    $allocatedTasks[$task] += 1;

                $taskHours += $task->getHours();
            }
        }
        assert($freeSlots + $allocatedSlots === count($this->slots), "free=$freeSlots + allocated=$allocatedSlots !== count={$this->slots->count()}");
        assert(count($allocatedTasks) === $this->schedule->getTasks()->count(), "Task count doesnt match");

        return sprintf("id=%s period=%s | slots=%d free=%d | tasks=%d hours=%d consultants=%d",
            $this->id ?? $this->schedule->getUuid()->toRfc4122(),
            $this->period->asString(),
            count($this->slots),
            $freeSlots,
            count($allocatedTasks),
            $taskHours,
            count($this->tasksByConsultant)
        );
    }

    public function computeConsultantHours(): int
    {
        $total = 0;
        $onPremises = 0;
        foreach ($this->tasksByConsultant as $consultant) {
            /** @var Consultant $consultant */

            foreach ($this->tasksByConsultant[$consultant] as $task) {
                /** @var Task $task */
                $taskHours = $task->getHours();

                $total += $taskHours;
                if ($task->isOnPremises())
                    $onPremises += $taskHours;
            }
        }

        return $total;
    }

    public function getConsultantHours(Consultant $consultant): int
    {
        if (!isset($this->consultantHours[$consultant]))
            throw new \InvalidArgumentException("Consultant {$consultant} does not exist in schedule {$this->schedule}");

        return $this->consultantHours[$consultant];
    }

    public function getConsultantHoursOnPremises(Consultant $consultant): int
    {
        if (!isset($this->consultantHoursOnPremises[$consultant]))
            throw new \InvalidArgumentException("Consultant {$consultant} does not exist in schedule {$this->schedule}");

        return $this->consultantHoursOnPremises[$consultant];
    }

    /**
     * and could deal with straight tasks considering the fact that
     * loading tasks (from the database) into slots is the same problem.
     *
     * Sets $this as the owning schedule of each merged task.
     *
     * N.B. I assume each task is uniquely identified by (contracted service, on-premises)
     * N.B. 2: assert tasks do not overlap
     */
    public function merge(Schedule ...$sources)
    {
        foreach ($sources as $schedule) {
            foreach ($schedule->getTasks() as $task) {
                /** @var Task $task */
                $this->schedule->addTask($task);
//                $task->setSchedule($this->schedule);
            }
        }

        $this->reloadTasks();
    }

    /**
     * Returned slots are not necessarily adjacent.
     * @param Slot $slot
     * @param bool $sameDay
     * @param int $min
     * @param int $max
     * @return Slot[] Slots sorted by ascending distance
     */
    public function getClosestFreeSlots(Slot $slot, bool $sameDay, int $max = INF): array
    {
        /** @var Slot[] $slots */
        $slots = [];

        do {
            $slots[] = $next = $this->getClosestFreeSlot($slot);

            if ($next === null ||
                    ($sameDay && end($slots)->getStart()->format(static::DATE_NOTIME) != $next->getStart()->format(static::DATE_NOTIME)))
                break;

        } while (count($slots) <= $max);

        return $slots;
    }

    /**
     * @return Slot|null
     */
    public function getRandomFreeSlot(Period $period = null): ?Slot
    {
        $slots = $this->slots;

        if (!$period)
            $period = $this->schedule->getPeriod();

        assert($this->schedule->getPeriod()->contains($period));

        $afterIndex = $this->getPeriodStartSlot($period)->getIndex();
        $beforeIndex = $this->getPeriodEndSlot($period)->getIndex();

        $index = rand($afterIndex, $beforeIndex);
        /** @var Slot $slot */
        $slot = $slots[$index];

        if ($slot->isFree())
            return $slot;

        $direction = match (rand(0, 2)) {
            0 => 'before',
            1 => 'after',
            default => 'both'
        };

        $slot = $this->getClosestFreeSlot($slot, period: $period, direction: $direction);

//        assert($slot->isFree() === true, "Slot expected to be free");
        return $slot;
    }

    /**
     * @return Slot[]
     */
    public function getRandomFreeAdjacentSlots(int $min = 1, int $max = 1): array
    {

    }

    private function getPeriodStartSlot(Period $period): Slot
    {
        $start = $period->includedStart();

        while (!isset($this->slotsByDayHours[$hash = $start->format(static::DATE_HOURHASH)])) {
            $start = $start->modify('+1 hour');

            if ($period->endsBefore($start))
                throw new \RuntimeException("Unable to find a valid date within period {$period->asString()}: dayhash=$hash");
        }

        return $this->slotsByDayHours[$hash];
    }

    private function getPeriodEndSlot(Period $period): Slot
    {
        $end = $period->includedEnd();

        while (!isset($this->slotsByDayHours[$hash = $end->format(static::DATE_HOURHASH)])) {
            $end = $end->modify('-1 hour');

            if ($period->startsAfter($end))
                throw new \RuntimeException("Unable to find a valid date within period {$period->asString()}: dayhash=$hash");
        }

        return $this->slotsByDayHours[$hash];
    }

    /**
     * @return Slot[]
     */
    public function getRandomFreeSlotsSameDay(int $min = 1, int $preferred = 1, Period $period = null): array
    {
        if ($min < 1 || $preferred < 1)
            throw new \InvalidArgumentException("$min < 1 || $preferred < 1");

        if (!$period)
            $period = $this->schedule->getPeriod();

        /** @var Slot[] $slots */
        $initialSlot = $this->getRandomFreeSlot($period);
        if (!$initialSlot)
            return []; // No more slots available in the period

        $slots = [$initialSlot];

        if ($min == 1 && $preferred == 1)
            return $slots;

        // Get all free slots in the day, then random pick slots until satisfied
        $hash = $initialSlot->getStart()->format(static::DATE_DAYHASH);
        assert(is_array($this->slotsByDay[$hash]), "Empty slotsByDay for day hash $hash");

        // does include initial slot
        $freeSlots = array_filter($this->slotsByDay[$hash], fn(/** @var Slot $s */$s) => $s->isFree());

        $dog = 1000;
        while ((count($slots) < $min || count($slots) < $preferred) && $dog--) {
            // add another slot

            // try adjacent ones
            $last = array_search($slots[array_key_last($slots)], $freeSlots, true);
            $first = array_search($slots[array_key_first($slots)], $freeSlots, true);
            if (isset($freeSlots[$last + 1]))
                $slot = $freeSlots[$last + 1];
            elseif (isset($freeSlots[$first - 1]))
                $slot = $freeSlots[$first - 1];
            else
                $slot = $freeSlots[array_rand($freeSlots)];

            if (!in_array($slot, $slots))
                array_push($slots, $slot);

            if (count($slots) == count($freeSlots))
                break; // no more free slots available
        }

        assert(empty(array_filter($slots, fn(/** @var Slot $s */ $s) => $s->isAllocated())), "Returning non free slots");

        return $slots;
    }

    public function getFreeSlots(): array
    {

    }

    /**
     * Closest does not mean adjacent or in the same day.
     */
    public function getClosestFreeSlot(Slot $slot, Period $period = null, string $direction = 'both'): ?Slot
    {
        if (!$period) $period = $this->schedule->getPeriod();
        assert($period->contains($slot->getPeriod()));

        $slotIndex = $slot->getIndex();
        $slots = $this->slots;
        assert(-1 < $slotIndex && $slotIndex < $slots->getSize(), "Given slot index {$slotIndex} out of bounds [0, {$slots->getSize()}]");

        $closestBefore = $closestAfter = null;
        $closestBeforeDistance = $closestAfterDistance = INF;

        // find closest before, including the initial slot
        if ($direction == 'both' || $direction == 'before') {
            for ($offset = 0, $idx = $slotIndex; $idx >= 0; $idx =  --$offset + $slotIndex) {
                /** @var Slot $slot */
                $slot = $slots[$idx];

                if (!$period->contains($slot->getPeriod()))
                    break;

                if ($slot->isFree()) {
                    $closestBefore = $slot;
                    $closestBeforeDistance = abs($offset);
                    break;
                }
            }
        }

        // find closest after, including the initial slot
        if ($direction == 'both' || $direction == 'after') {
            for ($offset = 0, $idx = $slotIndex; $idx < $slots->getSize(); $idx = ++$offset + $slotIndex) {
                /** @var Slot $slot */
                $slot = $slots[$idx];

                if (!$period->contains($slot->getPeriod()))
                    break;

                if ($slot->isFree()) {
                    $closestAfter = $slot;
                    $closestAfterDistance = $offset;
                    break;
                }
            }
        }

        // None found, out of free slots
        if (!$closestBefore && !$closestAfter)
            return null;

        // Both found and equally distant
        if ($closestBeforeDistance === $closestAfterDistance && $closestBefore && $closestAfter)
            return [$closestBefore,$closestAfter][rand(0, 1)];

        if ($closestBefore && $closestBeforeDistance < $closestAfterDistance) {
            // N.B. ($closestBefore !== null && $closestAfter === null) => $closestBeforeDistance < $closestAfterDistance(=INF)
            return $closestBefore;
        }

        if ($closestAfter && $closestAfterDistance < $closestBeforeDistance) {
            // N.B. ($closestAfter !== null && $closestBefore === null) => $closestAfterDistance < $closestBeforeDistance(=INF)
            return $closestAfter;
        }

        assert(false, 'This should not be executed');
    }


    public function consolidateSameDayAdjacentTasks(): void
    {
        throw new \RuntimeException('not implmente');
        
        // does not store duplicated tasks
        $dayTasks = new \SplObjectStorage();

        $prevSlot = null;
        $prevSlotDayHash = null;
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $dayHash = $slot->getStart()->format(static::DATE_DAYHASH);

            foreach ($slot->getTasks() as $task) {
                // if already present (via equality), then extend the present one to slot->getEnd
                // --> AND
                $dayTasks->attach($task); //
            }

            if ($prevSlot && $prevSlotDayHash !== $dayHash) {
                // consolidate?
            }

            $prevSlot = $slot;
            $prevSlotHash = $dayHash;
        }

        // consolidation
        // consolidate when the task is no more present
    }
}
