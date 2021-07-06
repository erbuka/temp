<?php


namespace App;


use App\Entity\AddTaskCommand;
use App\Entity\Consultant;
use App\Entity\ContractedService;
use App\Entity\MoveTaskCommand;
use App\Entity\RemoveTaskCommand;
use App\Entity\Schedule;
use App\Entity\ScheduleChangeset;
use App\Entity\Task;
use App\Repository\ScheduleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use App\Validator\Constraints as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class ScheduleManager implements ScheduleInterface
{
    const DATE_NOTIME = 'Y-m-d';
    const DATE_HOURHASH = 'YmdH';
    const DATE_DAYHASH = 'Ymd';

    const VIOLATION_MULTIPLE_CONSULTANTS = 'Consultant schedule contains tasks of multiple consultants: {{ consultant }} !== {{ consultant_extraneous }}';
    const VIOLATION_DISCONTINUOUS_TASK = "Task {{ task }} of contracted service {{ contracted_service }} is split across discontinuous slots on day {{ day }}";
    const VIOLATION_TASKS_OVERLAPPING = "Task {{ task }} overlaps with task {{ task_overlapped }}";

    #[Assert\Valid]
    private Schedule $schedule;
    protected ?WorkflowInterface $taskWorkflow;
    private ScheduleChangeset $changeset;

    //region Indices
    private \SplFixedArray $slots; // NOTA BENE: this is shared (by ref) with Schedule.slots
    /** @var array<string, Slot> e.g. '2021020318' => Slot */
    private array $slotsByDayHours;
    /** @var array<string, Slot[]> e.g. '20210203' => Slot */
    private array $slotsByDay;
    /** @var \SplObjectStorage<Consultant, \SplObjectStorage>  */
    private \SplObjectStorage $tasksByConsultant;
    /** @var \SplObjectStorage<Consultant, int> **TOTAL** consultant hours (simplifies testing) */
    private \SplObjectStorage $consultantHours;
    /** @var \SplObjectStorage<Consultant, int>  */
    private \SplObjectStorage $consultantHoursOnPremises;
    //endregion Indices

    /**
     * NOTA BENE: called once for each Schedule via ScheduleManagerFactory.
     *
     * @param Schedule $schedule
     * @param WorkflowInterface|null $taskWorkflow
     */
    public function __construct(Schedule $schedule, ?WorkflowInterface $taskWorkflow = null)
    {
        $this->schedule = $schedule;
        $this->taskWorkflow = $taskWorkflow;

        $this->reloadTasks();
    }

    private function initializeChangeset(): void
    {
        $this->changeset = new ScheduleChangeset($this->schedule);
    }

    private function generateSlots()
    {
        assert(!isset($this->slots), "Refusing to overwrite existing slots in Schedule");

        $eligibleDays = [];
        $slotsMap = [];
        $byDay = [];

        foreach (Period::make($this->schedule->getFrom(), $this->schedule->getTo(), Precision::DAY(), Boundaries::EXCLUDE_END()) as $dayHash) {
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
                $hash = static::getDayHourHash($slot);
                $dayHash = static::getDayHash($slot);
                assert(!isset($slotsMap[$hash]), "Multiple slots have the same hash {$hash}");

                $slotsMap[$hash] = $slot;
                $eligibleDays[] = $slot;

                if (!isset($byDay[$dayHash])) $byDay[$dayHash] = [];
                array_push($byDay[$dayHash], $slot);

                assert($slot->getIndex() === array_search($slot, $eligibleDays, strict: true));
            }
        }

        $this->setSlots(\SplFixedArray::fromArray($eligibleDays));
        $this->slotsByDayHours = $slotsMap;
        $this->slotsByDay = $byDay;

    }

    private function setSlots(\SplFixedArray $slots): void
    {
        $this->slots = $slots;
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
        $this->initializeIndices();
        $this->initializeChangeset();

        // Load tasks
        $tasks = $this->schedule->getTasks()->matching(ScheduleRepository::createTasksSortedByStartCriteria());
        foreach ($tasks as $task) {
            /** @var Task $task */
            $this->addTaskIntoIndices($task);
        }
    }

    /**
     * @param Consultant $consultant
     * @return Task[]
     */
    public function getConsultantTasks(Consultant $consultant): array
    {
        if (!$this->tasksByConsultant->contains($consultant))
            return [];

        return iterator_to_array($this->tasksByConsultant[$consultant]);
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
    public function merge(ScheduleInterface ...$sources)
    {
        foreach ($sources as $schedule) {
            foreach ($schedule->getTasks() as $task) {
                /** @var Task $task */

                // TODO should use $this->addTask()
                $this->schedule->addTask($task);
//                $task->setSchedule($this->schedule);
            }
        }

        $this->reloadTasks();
    }

    /**
     * @return Slot|null
     */
    public function getRandomFreeSlot(Period $period = null): ?Slot
    {
        $slots = $this->slots;

        if (!$period)
            $period = $this->getSchedulePeriod();

        assert($this->getSchedulePeriod()->contains($period));

        $afterIndex = $this->getPeriodStartSlot($period)->getIndex();
        $beforeIndex = $this->getPeriodEndSlot($period)->getIndex();

        $index = rand($afterIndex, $beforeIndex);
        /** @var Slot $slot */
        $slot = $slots[$index];

        if ($slot->isFree())
            return $slot;

        try {
            $slot = $this->getClosestFreeSlot($slot, period: $period, direction: match (rand(0, 1)){ 0 => 'before', 1 => 'after' });
        } catch (NoSlotsAvailableException $e) {
            $slot = $this->getClosestFreeSlot($slot, period: $period);
        }

//        assert($slot->isFree() === true, "Slot expected to be free");
        return $slot;
    }

    /**
     * @return Slot[]
     */
    protected function getRandomFreeSlotsSameDay(int $min = 1, int $preferred = 1, Period $period = null): array
    {
        if ($min < 1 || $preferred < 1)
            throw new \InvalidArgumentException("$min < 1 || $preferred < 1");

        if (!$period)
            $period = $this->getSchedulePeriod();

        /** @var Slot[] $slots */
        $initialSlot = $this->getRandomFreeSlot($period);
        if (!$initialSlot)
            return []; // No more slots available in the period

        $slots = [$initialSlot];

        if ($min == 1 && $preferred == 1)
            return $slots;

        // Get all free slots in the day, then random pick slots until satisfied
        $hash = static::getDayHash($initialSlot);
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

    /**
     * May return more slots than $preferred, in which case it is up to the caller to chose how many slots to use.
     *
     * PROBLEM: period is enforced with hourly precision, that is if the random picked slot is >= $min in a day that has more than 1 adjacent slots free,
     * the picked slot is returned because it >= $min and belongs to the day.
     *
     * @return Slot[] sorted by ascending time
     * @throws NoSlotsAvailableException
     */
    protected function getRandomFreeAdjacentSameDaySlots(int $min = 1, int $preferred = 1, Period $period = null): array
    {
        if ($min < 1 || $preferred < 1)
            throw new \InvalidArgumentException("$min < 1 || $preferred < 1");

        if (!$period)
            $period = $this->getSchedulePeriod();

        assert($this->getSchedulePeriod()->contains($period));

        // Starts
        $afterIndex = $this->getPeriodStartSlot($period)->getIndex(); // Included
        $beforeIndex = $this->getPeriodEndSlot($period)->getIndex(); // Included

        /** @var Slot[] $slots */
        $initialSlot = $this->getRandomFreeSlot($period);
        if (!$initialSlot)
            throw new NoSlotsAvailableException("period={$period->asString()}");

        /** @var Slot[] $closestAfter */
        /** @var Slot[] $closestBefore */
        $closestAfter = $closestBefore = null;
        $closestAfterDistance = $closestBeforeDistance = INF;

        // find closest afterwards, including given slot
        $initialSlot = reset($this->slotsByDay[static::getDayHash($initialSlot)]);
        $adjacentSlots = [];
        $dayAdjacentSlots = [];
        $prevDay = null;
        for ($idx = $initialSlot->getIndex(); $idx <= $beforeIndex; $idx++) {
            // First iteration is the initial slot
            /** @var Slot $slot */
            $slot = $this->slots[$idx];

            // If new day, then decide whether to return found adjacent slots or keep going
            if (($dayHash = static::getDayHash($slot)) !== $prevDay) {
                if (count($adjacentSlots) >= $min)
                    $dayAdjacentSlots[] = $adjacentSlots;

                if (count($dayAdjacentSlots) > 0) {
                    // Slot blocks will have at least $min elements

                    $largest = null;
                    foreach ($dayAdjacentSlots as $slots) {
                        /** @var Slot[] $slots */
                        if (!isset($largest) || count($slots) > count($largest))
                            $largest = $slots;

                        if (count($largest) >= $preferred) {
                            break;
                        }
                    }

                    assert(isset($largest));
                    $closestAfter = $largest;
                    $closestAfterDistance = $initialSlot->getIndex() - reset($closestAfter)->getIndex();
                    break;
                }

                // Resets structures on new day
                $adjacentSlots = [];
                $dayAdjacentSlots = [];
                $prevDay = $dayHash;
            }


            if ($slot->isFree())
                $adjacentSlots[] = $slot;
            else {
                // Store adjacent slot in current day
                if (count($adjacentSlots) >= $min)
                    $dayAdjacentSlots[] = $adjacentSlots;

                $adjacentSlots = [];
            }
        }

        // Find closest earlier, including given slot
        if (isset($this->slots[$initialSlot->getIndex() - 1]))
            $initialSlot = $this->slots[$initialSlot->getIndex() - 1]; // Start from last slot of previous day

        $adjacentSlots = [];
        $dayAdjacentSlots = [];
        $prevDay = null;
        for ($idx = $initialSlot->getIndex(); $idx >= $afterIndex; $idx--) {
            // First iteration is the initial slot
            /** @var Slot $slot */
            $slot = $this->slots[$idx];

            // If new day, then decide whether to return found adjacent slots or keep going
            if (($dayHash = static::getDayHash($slot)) !== $prevDay) {
                if (count($adjacentSlots) >= $min)
                    $dayAdjacentSlots[] = $adjacentSlots;

                if (count($dayAdjacentSlots) > 0) {
                    // Slot blocks will have at least $min elements

                    $largest = null;
                    foreach ($dayAdjacentSlots as $slots) {
                        /** @var Slot[] $slots */
                        if (!isset($largest) || count($slots) > count($largest))
                            $largest = $slots;

                        if (count($largest) >= $preferred) {
                            break;
                        }
                    }

                    assert(isset($largest));
                    $closestBefore = $largest;
                    usort($closestBefore, fn($s1, $s2) => $s1->getStart() <=> $s2->getStart());
                    $closestBeforeDistance = end($closestBefore)->getIndex() - $initialSlot->getIndex();
                    break;
                }

                // Resets structures on new day
                $adjacentSlots = [];
                $dayAdjacentSlots = [];
                $prevDay = $dayHash;
            }

            if ($slot->isFree())
                $adjacentSlots[] = $slot;
            else {
                // Store adjacent slot in current day
                if (count($adjacentSlots) >= $min)
                    $dayAdjacentSlots[] = $adjacentSlots;

                $adjacentSlots = [];
            }
        }

        if (!$closestAfter && !$closestBefore)
            throw new NoSlotsAvailableException("No free slots available in period {$period->asString()}");

        // Both found and equally distant
        if ($closestBeforeDistance === $closestAfterDistance && $closestBefore && $closestAfter)
            return [$closestBefore,$closestAfter][rand(0, 1)];

        if ($closestBefore && $closestBeforeDistance < $closestAfterDistance) {
            // N.B. ($closestBackwards !== null && $closestForward === null) => $closestBackwardsDistance < $closestForwardDistance(=INF)
            return $closestBefore;
        }

        if ($closestAfter && $closestAfterDistance < $closestBeforeDistance) {
            // N.B. ($closestForward !== null && $closestBackwards === null) => $closestForwardDistance < $closestBackwardsDistance(=INF)
            return $closestAfter;
        }

        throw new \LogicException('This should not be executed');
    }

    //region Allocation API

    public function allocateAdjacentSameDaySlots(Task $task, int $min = 1, int $preferred = 1, Period $period = null): int
    {
        if ($min < 1 || $preferred < 1 || $preferred < $min)
            throw new \InvalidArgumentException(__METHOD__ ." $min < 1 || $preferred < 1 || $preferred < $min");

        $slots = $this->getRandomFreeAdjacentSameDaySlots($min, $preferred, $period);

        if (count($slots) < $min)
            throw new NoSlotsAvailableException("Period={$period->asString()} min={$min} preferred={$preferred}");

        $end = $slots[0]->getEnd();
        $allocated = 1;
        while ($allocated < $preferred && $allocated < count($slots))
        {
            $end = $slots[$allocated++]->getEnd();
        }

        $task->setStart($slots[0]->getStart());
        $task->setEnd($end);

        if ($this->taskWorkflow) {
            $this->taskWorkflow->getMarking($task); // Sets initial marking + triggers workflow events
        }

        $this->addTask($task);

        assert($task->getHours() === $allocated);
        assert($allocated >= $min);
        assert($allocated <= $preferred);

        return $allocated;
    }

    //endregion Allocation API

    private function getPeriodStartSlot(Period $period): Slot
    {
        $start = $period->includedStart();

        while (!isset($this->slotsByDayHours[$hash = static::getDayHourHash($start)])) {
            $start = $start->modify('+1 hour');

            if ($period->endsBefore($start))
                throw new \RuntimeException("Unable to find a valid date within period {$period->asString()}: dayhash=$hash");
        }

        return $this->slotsByDayHours[$hash];
    }

    private function getSlotFromDateTime(\DateTimeInterface $dateTime): ?Slot
    {
        $dayHourHash = static::getDayHourHash($dateTime);

        if (!isset($this->slotsByDayHours[$dayHourHash]))
            return null;

        return $this->slotsByDayHours[$dayHourHash];
    }

    private function getPeriodEndSlot(Period $period): Slot
    {
        $end = $period->includedEnd();

        while (!isset($this->slotsByDayHours[$hash = static::getDayHourHash($end)])) {
            $end = $end->modify('-1 hour');

            if ($period->startsAfter($end))
                throw new \RuntimeException("Unable to find a valid date within period {$period->asString()}: dayhash=$hash");
        }

        return $this->slotsByDayHours[$hash];
    }

    /**
     * Closest does not mean adjacent or in the same day.
     * @throws NoSlotsAvailableException
     */
    protected function getClosestFreeSlot(Slot $slot, Period $period = null, string $direction = 'both'): Slot
    {
        if (!$period) $period = $this->getSchedulePeriod();
        assert($this->getSchedulePeriod()->contains($period));
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
        if (!$closestBefore && !$closestAfter) {
            throw new NoSlotsAvailableException("No free slots available on schedule {$this->schedule} period={$period->asString()}". $this->getStats());
        }

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

        throw new \LogicException('This should not be executed');
    }

    /**
     * Assumes slots ordered by time.
     */
    public function consolidateSameDayAdjacentTasks(): void
    {
        $prevSlot = null;
        $prevSlotDayHash = null;
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $dayHash = static::getDayHash($slot);

            // Skip initial slot
            if ($prevSlot && $prevSlotDayHash) {
                foreach ($slot->getTasks() as $task) {
                    // if already present (via equality), then extend the present one to slot->getEnd()
                    foreach ($prevSlot->getTasks() as $prevTask) {
                        /** @var Task $prevTask */
                        if ($prevTask->sameActivityOf($task) && $prevTask !== $task && $prevSlotDayHash === $dayHash) {
                            /*
                             * Expands the previous task to include this slot and shrinks the current task.
                             *
                             * CACHE
                             *  - does not change total hours
                             *  - changes individual task hours
                             *  - does not add new tasks
                             *  - removes tasks from schedule
                             */

                            // Shrinks the adjacent task by removing it from the current slot.
                            $task->setStart($slot->getEnd());
                            $slot->removeTask($task);

                            // Expands the repeated task in the current slot
                            $slot->addTask($prevTask);
                            $prevTask->setEnd($slot->getEnd());

                            // Remove the adjacent task if it has been completely merged.
                            if ($task->getStart()->diff($task->getEnd(), true)->h === 0) {
                                $this->removeTask($task);
                            }
                        }
                    }
                }
            }

            $prevSlot = $slot;
            $prevSlotDayHash = $dayHash;
        }
    }

    /**
     * - $this->slots are implicitly ordered by ascending time
     */
    public function consolidateNonOverlappingTasksDaily(): void
    {
        $daySlots = []; // slots by day
        $dayTasks = [];
        // Slots ordered by ascending time

        // Group tasks by day
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            assert(count($slot->getTasks()) < 2, "Slot {$slot} contains overlapping tasks");

            $dayHash = static::getDayHash($slot);
            if (!isset($dayTasks[$dayHash])) {
                $dayTasks[$dayHash] = [];
            }
//            if (!isset($daySlots[$dayHash])) {
//                $daySlots[$dayHash] = [];
//            }
//
//            $daySlots[$dayHash][] = $slot;

            foreach ($slot->getTasks() as $task) {
                /** @var Task $task */
                if (!in_array($task, $dayTasks[$dayHash])) {
                    $dayTasks[$dayHash][] = $task;
                }
            }
        }

        foreach ($dayTasks as $dayHash => &$tasks) {
            /** @var Task[] $tasks */
            if (count($tasks) < 2) continue;

            $slots = $this->slotsByDay[$dayHash];

            // Ensure tasks are ordered by ascending start
            usort($tasks, fn(/** @var Task $t1 */ $t1, /** @var Task $t2 */ $t2) => $t1->getStart() <=> $t2->getStart());

            $dayTasksHoursTotal = array_reduce($tasks, fn($sum, /** @var Task $t */ $t) => $sum + (int) $t->getHours(), 0);
            $dayTasksHoursOnPremises = array_reduce($tasks, fn($sum, /** @var Task $t */ $t) => $sum + ($t->getHours() * $t->isOnPremises() ? 1 : 0), 0);
            $dayAllocatedSlots = count(array_filter($slots, fn($s) => $s->isAllocated()));
            assert($dayTasksHoursTotal === $dayAllocatedSlots);

//            echo sprintf("\nDay {$dayHash} hours_total={$dayTasksHoursTotal} hours_on_premises={$dayTasksHoursOnPremises}");

            $reallocatedTasks = []; // tasks removed

            foreach ($tasks as $i => $task) {
                if (in_array($task, $reallocatedTasks)) {
                    continue;
                }

                $matches = array_filter($tasks, fn(/** @var Task $t */ $t) =>
                    $t !== $task
                    && $t->sameActivityOf($task)
//                    && $t->getStart() > $task->getStart() // Prevents matching already iterated on tasks
                );
                if (empty($matches)) continue;

                $matchesHours = array_reduce($matches, fn($sum, $t) => $sum + (int) $t->getHours(), 0);
                assert($matchesHours > 0, "Task hours $matchesHours > 0");

                // Expands the task's end to include matched tasks hours.
                // This does not reflect the exact start/end of the task as these are reassigned
                // when the task is spread across daily slots (see below)
                $task->setEnd(\DateTime::createFromInterface($task->getEnd())->add(new \DateInterval("PT{$matchesHours}H")));

                array_push($reallocatedTasks, ...$matches);
            }

            if (empty($reallocatedTasks))
                continue; // no task has been merged.

            // Remove merged tasks.
            foreach ($tasks as $i => $task)
                if (in_array($task, $reallocatedTasks)) {
                    unset($tasks[$i]);
                    $this->removeTask($task); // TODO entityManager::delete() somehow

//                    echo "\nRemoved task {$task}";
                }

            // Empty all slots
            foreach ($slots as $slot) {
                /** @var Slot $slot */
                $slot->empty();
            }

            $dayTasksHoursTotal_after = array_reduce($tasks, fn($sum, $t) => ($sum + (int) $t->getHours()), 0);
            assert($dayTasksHoursTotal === $dayTasksHoursTotal_after);

            // Reallocate tasks
//            $latestSlotIndex = rand(0, count($slots) - $dayTasksHoursTotal);
            $latestSlotIndex = 0;
            foreach ($tasks as $task) {
                /** @var Task $task */
                assert($latestSlotIndex < count($slots));

                $hours = $task->getHours();

                $assignedSlots = array_slice($slots, $latestSlotIndex, $hours);
                $task->setEnd(end($assignedSlots)->getEnd());
                $task->setStart(reset($assignedSlots)->getStart());

                foreach ($assignedSlots as $slot) {
                    /** @var Slot $slot */
                    $slot->addTask($task);
                    $latestSlotIndex++;
                }
            }

            $dayTasksHoursTotal_after = array_reduce($tasks, fn($sum, $t) => $sum + (int) $t->getHours(), 0);
            $dayTasksHoursOnPremises_after = array_reduce($tasks, fn($sum, /** @var Task $t */ $t) => $sum + ($t->getHours() * $t->isOnPremises() ? 1 : 0), 0);
            $dayAllocatedSlots_after = count(array_filter($slots, fn($s) => $s->isAllocated()));
            if ($dayTasksHoursTotal !== $dayTasksHoursTotal_after) {
                assert($dayTasksHoursTotal === $dayTasksHoursTotal_after);
            }
            if ($dayTasksHoursOnPremises ==! $dayTasksHoursOnPremises_after) {
                assert($dayTasksHoursOnPremises === $dayTasksHoursOnPremises_after);
            }
            if ($dayAllocatedSlots !== $dayAllocatedSlots_after) {
                assert($dayAllocatedSlots === $dayAllocatedSlots_after);
            }
        }
    }





    //region Commands API

    public function addTask(Task $task): static
    {
        if (!$this->containsTask($task)) {
            $command = new AddTaskCommand($this->schedule, $task);

            $command->execute();
            $this->changeset->addCommand($command);

            $this->addTaskIntoIndices($task);
        }

        return $this;
    }

    public function removeTask(Task $task): static
    {
        if ($this->containsTask($task)) {
            $cmd = new RemoveTaskCommand($this->schedule, $task);

            $cmd->execute();
            $this->changeset->addCommand($cmd);

            $this->removeTaskFromIndices($task);
        }

        return $this;
    }

    /**
     * Slots in the given interval must be free.
     */
    public function moveTask(Task $task, Period $period): static
    {
        if (!$this->containsTask($task))
            throw new \InvalidArgumentException("Task {$task} does not belong to schedule {$this->schedule}");

        if (!$period->precision()->equals(Precision::HOUR()))
            throw new \InvalidArgumentException("Period precision '{$period->precision()->intervalName()}' does not match Precision::HOUR()");

        $newStartSlot = $this->getSlotFromDateTime($period->includedStart());
        $newEndSlot = $this->getSlotFromDateTime($period->includedEnd());

        // Period must be within schedule boundaries
        if (!$newStartSlot || !$newEndSlot)
            throw new \InvalidArgumentException("Period {$period->asString()} is outside schedule period {$this->getSchedulePeriod()->asString()}: unable to find lookup slots");

        // Target slots must be free
        for ($i = $newStartSlot->getIndex(); $i <= $newEndSlot->getIndex(); $i++) {
            /** @var Slot $slot */
            $slot = $this->slots[$i];

            if ($slot->isAllocated() && !$slot->containsTask($task))
                throw new \LogicException("Refusing to move task {$task} to period {$period->asString()} because the slots are not free");
        }

        // Remove task from indices
        $this->removeTaskFromIndices($task);

        // Update task period
        $cmd = new MoveTaskCommand($this->schedule, $task,
            start: $period->includedStart(),
            end: $period->includedEnd()->add(new \DateInterval('PT1H'))
        );
        $cmd->execute();
        $this->changeset->addCommand($cmd);

        // Reindex the updated task
        $this->addTaskIntoIndices($task);

        return $this;
    }

    //endregion Commands

    public function getSchedulePeriod(Precision $precision = null): Period
    {
        if (!$precision)
            $precision = Precision::HOUR();

        return Period::make($this->schedule->getFrom(), $this->schedule->getTo(), $precision, Boundaries::EXCLUDE_END());
    }

    /**
     * Shadows the underlying collection to prevent modification outside of manager methods.
     * But that's petty because one could always change task start/end time.
     *
     * @return Collection
     */
    public function getTasks(): Collection
    {
        return new ArrayCollection($this->schedule->getTasks()->toArray());
    }

    public function getFrom(): \DateTimeInterface
    {
        return $this->schedule->getFrom();
    }

    public function getTo(): \DateTimeInterface
    {
        return $this->schedule->getTo();
    }

    public function containsTask(Task $task): bool
    {
        $contains = $this->schedule->getTasks()->contains($task);

        // Task must be included in tasksByConsultant
        // problem: consolidateAdjacentSameDayTasks removes the task from all slots before invoking delete
//        if ($contains) {
//            assert($this->tasksByConsultant[$task->getConsultant()]->contains($task), "tasksByConsultant out of sync with underlying tasks collection: missing task {$task}");
//            assert(!empty($slots = array_filter($this->slots->toArray(), fn(/** @var Slot $slot */ $slot) => $slot->containsTask($task))), "::slots out of sync with schedule's tasks collection");
//        }

        return $contains;
    }

    /**
     * Checks whether the task has been properly loaded into the manager's cache structures.
     *
     * TODO: this method has a high performance impact when run for every task.. should be avoided and other alternatives evaluated (e.g. test) for consistency checks.
     *
     * @param Task $task
     * @return bool
     */
    private function isTaskLoaded(Task $task): bool
    {
        assert($this->schedule->getTasks()->contains($task));

        // Cheaper
        $loaded = !empty(array_filter(
            $this->slots->toArray(),
            fn($slot) => $slot->containsTask($task))
        );

        assert(!$loaded || in_array($task, $this->tasksByConsultant[$task->getConsultant()], true));
        assert(!$loaded || !empty(array_filter(
            $this->slotsByDay[static::getDayHash($task)],
            fn($slot) => $slot->containsTask($task)))
        );

        return $loaded;
    }

    protected static function getDayHash(mixed $o): int
    {
        if ($o instanceof Slot)
            return (int)$o->getStart()->format(static::DATE_DAYHASH);
        if ($o instanceof Task)
            return (int)$o->getStart()->format(static::DATE_DAYHASH);
        if ($o instanceof \DateTimeInterface)
            return (int)$o->format(static::DATE_DAYHASH);

        throw new \InvalidArgumentException("Object of type ". $o::class ."not supported");
    }

    protected static function getDayHourHash(mixed $o): int
    {
        if ($o instanceof Slot)
            return (int)$o->getStart()->format(static::DATE_HOURHASH);
        if ($o instanceof Task)
            return (int)$o->getStart()->format(static::DATE_HOURHASH);
        if ($o instanceof \DateTimeInterface)
            return (int)$o->format(static::DATE_HOURHASH);

        throw new \InvalidArgumentException("Object of type ".$o::class."not supported");
    }

    public function getScheduleChangeset(): ScheduleChangeset
    {
        return $this->changeset;
    }

    //region Indices operations

    protected function addTaskIntoIndices(Task $task)
    {
        $consultant = $task->getConsultant();
        // A task is considered to belong to a slot iff its starting
        $period = Period::make($task->getStart(), $task->getEnd(), Precision::HOUR(), Boundaries::EXCLUDE_END());

        $allocatedSlots = new \SplObjectStorage();
        foreach ($period as $hour) {
            $key = static::getDayHourHash($hour);

            if (!isset($this->slotsByDayHours[$key]))
                throw new \RuntimeException("Task {$period->asString()} is outside this schedule boundaries {$this->getSchedulePeriod()->asString()}");

            assert($this->slotsByDayHours[$key] instanceof Slot, "Map does not return a slot");

            $slot = $this->slotsByDayHours[$key];
            $slot->addTask($task);
            $allocatedSlots->attach($slot);
        }
        assert(count($allocatedSlots) === $task->getHours());
        assert($period->length() === $task->getHours());

        if (!isset($this->tasksByConsultant[$consultant]))
            $this->tasksByConsultant[$consultant] = new \SplObjectStorage();
        $this->tasksByConsultant[$consultant]->attach($task);

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

    protected function removeTaskFromIndices(Task $task)
    {
        $period = Period::make($task->getStart(), $task->getEnd(), Precision::HOUR(), Boundaries::EXCLUDE_END());
        $consultant = $task->getConsultant();
        $removed = false;

        // Removes from slots. (optimized: if task is erroneously present in other slots, it does not get eliminated)
        foreach ($period as $hour) {
            $key = static::getDayHourHash($hour);
            if (!isset($this->slotsByDayHours[$key]))
                throw new \RuntimeException("Task {$period->asString()} is outside this schedule boundaries {$this->getSchedulePeriod()->asString()}");

            $slot = $this->slotsByDayHours[$key];
            $slot->removeTask($task);
        }

        // tasksByConsultant
        /** @var \SplObjectStorage $tasksByConsultant */
        $tasksByConsultant = $this->tasksByConsultant[$consultant];
        if ($tasksByConsultant->contains($task)) {
            $tasksByConsultant->detach($task);
            $removed = true;
        }

        // Update hours
        if ($removed) {
            $this->consultantHours[$consultant] -= $task->getHours();

            if ($task->isOnPremises())
                $this->consultantHoursOnPremises[$consultant] -= $task->getHours();
        }
    }

    protected function initializeIndices(): void
    {
        // Prevent expensive generation of slots which is not needed because schedule's from/to are invariants.
        if (!isset($this->slots))
            $this->generateSlots();
        else
            $this->clearSlots();

        $this->tasksByConsultant = new \SplObjectStorage();
        $this->consultantHours = new \SplObjectStorage();
        $this->consultantHoursOnPremises = new \SplObjectStorage();
    }

    //endregion Indices operations

    //region Validation callbacks

    /**
     * These validation callbacks are implicitly applied only for individual consultant schedules.
     * @param ExecutionContextInterface $context
     * @return bool
     */
    #[Assert\Callback(groups: ['consultant'])]
    public function validateTasksSameConsultant(ExecutionContextInterface $context): void
    {
        /** @var Consultant $consultant */
        $consultant = null;

        foreach ($this->schedule->getTasks() as $task) {
            /** @var Task $task */
            if (!isset($consultant)) {
                $consultant = $task->getConsultant();
                continue;
            }

            if ($task->getConsultant() !== $consultant) {
                $context->buildViolation(static::VIOLATION_MULTIPLE_CONSULTANTS)
                    ->setParameter('{{ consultant }}', $consultant->getName())
                    ->setParameter('{{ consultant_extraneous }}', $task->getConsultant()->getName())
                    ->addViolation();
            }
        }
    }

    /**
     * Detects whether a task is spread across non-adjacent slots in the same day.
     *
     * Notice that a task is (consultant, recipient, service, on/off-premises).
     */
    #[Assert\Callback(groups: ['generation'])]
    public function detectDiscontinuousTasks(ExecutionContextInterface $context)
    {
        $dayTasks = [];
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $hash = $slot->getStart()->format(ScheduleManager::DATE_NOTIME);
            if (!isset($dayTasks[$hash]))
                $dayTasks[$hash] = [];

            foreach ($slot->getTasks() as $task) {
                if (!in_array($task, $dayTasks[$hash])) {
                    $dayTasks[$hash][] = $task;
                }
            }
        }

        foreach ($dayTasks as $day => $tasks) {
            /** @var Task[] $tasks */

            foreach ($tasks as $task) {
                /** @var Task $task */
                $matches = array_filter($tasks,
                    fn ($t) => $t !== $task
                        && $t->getContractedService() === $task->getContractedService()
                        && $t->isOnPremises() === $task->isOnPremises()
                );

                foreach ($matches as $m) {
                    $context->buildViolation(static::VIOLATION_DISCONTINUOUS_TASK)
                        ->setParameter('{{ task }}', (string) $m)
                        ->setParameter('{{ day }}', $day)
                        ->setParameter('{{ contracted_service }}', $task->getContractedService())
                        ->addViolation();
                }
            }
        }
    }

    /**
     * Should use only tasks.
     * Should not depend on EntityManager or other services. If this is needed, refactor to external constraint.
     * Advantage of using callbacks: access to private members.
     *
     * Assumes slots ordered by time ASC.
     *
     * @param ExecutionContextInterface $context
     */
    #[Assert\Callback(groups: ['consultant'])]
    public function detectOverlappingTasks(ExecutionContextInterface $context)
    {
        $taskOverlaps = new \SplObjectStorage(); // <Task, Task[]>

        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $tasks = $slot->getTasks(); // returns a copy of type array
            usort($tasks, fn(/** @var Task $t1 */ $t1, /** @var Task $t2 */ $t2) => $t1->getStart() <=> $t2->getStart());

            if (count($tasks) > 1) {
                $overlapped = array_shift($tasks);

                if (!isset($taskOverlaps[$overlapped]))
                    $taskOverlaps[$overlapped] = [];

                foreach ($tasks as $task) {
                    if (!in_array($task, $taskOverlaps[$overlapped]))
                        $taskOverlaps[$overlapped] = [...$taskOverlaps[$overlapped], $task];
                }
            }
        }

        foreach ($taskOverlaps as $overlapped) {
            /** @var Task $overlapped */
            $overlaps = $taskOverlaps[$overlapped];

            foreach ($overlaps as $task) {
                $context->buildViolation(static::VIOLATION_TASKS_OVERLAPPING)
                    ->setParameter('{{ task }}', $task)
                    ->setParameter('{{ task_overlapped }}', $overlapped)
//                        ->setInvalidValue($task)
//                        ->atPath("slot({$slot->getIndex()}).task({$task})")
                    ->addViolation();
            }
        }
    }

    //endregion Validation callbacks

    //region Metrics

    /**
     * Do not use cached results.
     *
     * @return string
     */
    public function getStats(): string
    {
        $allocatedSlots = 0;
        $freeSlots = 0;
//        $slotsCount = $this->slots->getSize(); // ::count() and count() are equivalent to ::getSize()
        $allocatedTasks = new \SplObjectStorage(); // Task => count of slots in which the task is included
        $taskHours = 0;
        $taskHoursOnPremises = 0;

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

                $taskHours += 1;
                $taskHoursOnPremises += $task->isOnPremises() ? 1 : 0;
            }
        }
        assert($freeSlots + $allocatedSlots === count($this->slots), "free=$freeSlots + allocated=$allocatedSlots !== count={$this->slots->count()}");
        assert(count($allocatedTasks) === $this->schedule->getTasks()->count(), "Task count doesnt match");

        return sprintf("id=%s period=%s consultants=%d | slots=%d free=%d | tasks=%d hours=%d onpremises=%d",
            $this->id ?? $this->schedule->getUuid()->toRfc4122(),
            $this->getSchedulePeriod()->asString(),
            count($this->tasksByConsultant),
            count($this->slots),
            $freeSlots,
            count($allocatedTasks),
            $taskHours,
            $taskHoursOnPremises,
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

    public function countOnPremisesSlotsAllocatedToContractedService(ContractedService $cs): int
    {
        $count = 0;
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            if ($slot->isAllocatedOnPremisesToContractedService($cs))
                $count++;
        }

        return $count;
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


    public function countSlotsAllocatedToContractedService(ContractedService $cs): int
    {
        $count = 0;
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            if ($slot->isAllocatedToContractedService($cs))
                $count++;
        }

        return $count;
    }

    //endregion Metrics
}
