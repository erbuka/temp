<?php

namespace App\Entity;

use App\Repository\ScheduleRepository;
use App\ScheduleManager;
use App\Slot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator\Constraints as AppAssert;
use App\Validator\Schedule as ScheduleAssert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @ORM\Entity(repositoryClass=ScheduleRepository::class)
 */
#[ScheduleAssert\TasksWithinBounds]
#[ScheduleAssert\MatchContractedServiceHours(onPremisesOnly: true)]
class Schedule
{
    const DATE_NOTIME = 'Y-m-d';
    const DATE_SLOTHASH = 'YmdH';

    public string $violationMessageContractedServiceExcessDailyHours = "Contracted service {{ cs }} has {{ hours }} > 5 hours on day {{ day }}";
    const VIOLATION_TASKS_OVERLAPPING = "Task {{ task }} overlaps with task {{ task_overlapped }}";
    const VIOLATION_DISCONTINUOUS_TASK = "Task {{ task }} of contracted service {{ contracted_service }} is split across discontinuous slots on day {{ day }}";

    //region Persisted fields

    /**
     * @ORM\Id
     * @ORM\GeneratedValue
     * @ORM\Column(type="integer", options={"unsigned":true})
     */
    private int $id;

    /**
     * @ORM\Column(type="uuid")
     */
    private Uuid $uuid;

    /**
     * First day included in the period.
     *
     * @ORM\Column(name="`from`", type="datetime")
     */
    #[Assert\NotNull]
    #[AppAssert\DateTimeUTC]
    private \DateTimeInterface $from;

    /**
     * Day *after* last day included in the period
     * @ORM\Column(name="`to`", type="datetime")
     */
    #[Assert\NotNull]
    #[AppAssert\DateTimeUTC]
    private \DateTimeInterface $to;

    /**
     * See https://gist.github.com/pylebecq/f844d1f6860241d8b025#:~:text=What's%20the%20difference%20between%20cascade,than%20one%20object%20being%20deleted.
     * For performance considerations, see https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/working-with-associations.html#transitive-persistence-cascade-operations
     * in particular: "Cascade operations require collections and related entities to be fetched into memory"
     *
     * @ORM\OneToMany(targetEntity=Task::class, mappedBy="schedule", orphanRemoval=true, cascade={"persist"})
     * @ORM\OrderBy({"start" = "ASC"})
     * @var Collection<int, Task>
     */
    private Collection $tasks;

    //endregion Persisted fields

    /**
     * Slots are effectively managed by ScheduleManager which sets a reference to the slots when instantiated.
     */
    public \SplFixedArray $slots;
    private Period $period;
    private ?ScheduleManager $manager;

    /**
     * Invoked only when creating new entities inside the app.
     * Never invoked by Doctrine when retrieving objcts from the database.
     *
     * @param \DateTimeInterface $toDay excluded e.g. 2022-07-01T00:00:00 => 2022-06-30T23:00:00
     */
    public function __construct(\DateTimeInterface $fromDay, \DateTimeInterface $toDay)
    {
        $this->tasks = new ArrayCollection();
        $this->uuid = Uuid::v4();
        $this->period = Period::make($fromDay, $toDay, Precision::HOUR(), Boundaries::EXCLUDE_END());
        $this->from = $this->period->start();
        $this->to = $this->period->end();
    }

    /**
     * @param \DateTimeInterface|null $after
     * @param \DateTimeInterface|null $before
     * @return ?Slot
     */
    public function getRandomFreeSlot(\DateTimeInterface $after = null, \DateTimeInterface $before = null): ?Slot
    {
        $slots = $this->slots;

        $index = rand(0, $slots->getSize() - 1);
        /** @var Slot $slot */
        $slot = $slots[$index];

        if ($slot->isFree())
            return $slot;

        $direction = match (rand(0, 2)) {
            0 => 'before',
            1 => 'after',
            default => 'both'
        };

        return $this->getClosestFreeSlot($slot, $direction);
    }

    /**
     * @deprecated
     */
    public function getClosestFreeSlot(Slot $slot, string $direction = 'both'): ?Slot
    {
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

                if ($slot->isFree()) {
                    $closestBefore = $slot;
                    $closestBeforeDistance = $offset;
                    break;
                }
            }
        }

        // find closest after, including the initial slot
        if ($direction == 'both' || $direction == 'after') {
            for ($offset = 0, $idx = $slotIndex; $idx < $slots->getSize(); $idx = ++$offset + $slotIndex) {
                /** @var Slot $slot */
                $slot = $slots[$idx];

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

        assert(false, 'This should not happen');
    }

    /**
     * @deprecated
     * @return string
     */
    public function getStats(): string
    {
        assert($this->manager !== null);
        return $this->manager->getStats();
    }

    /**
     * - $this->slots are implicitly ordered by ascending time
     */
    public function consolidateNonOverlappingTasksDaily(): void
    {
        $daySlots = [];
        $dayTasks = [];
        // Slots ordered by ascending time

        // Group tasks by day
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            assert(count($slot->getTasks()) < 2, "Slot {$slot} contains overlapping tasks");

            $dayHash = $slot->getStart()->format(static::DATE_NOTIME);
            if (!isset($dayTasks[$dayHash])) {
                $dayTasks[$dayHash] = [];
            }
            if (!isset($daySlots[$dayHash])) {
                $daySlots[$dayHash] = [];
            }

            $daySlots[$dayHash][] = $slot;

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

            $slots = $daySlots[$dayHash];

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

    /**
     * @param Slot $initialSlot
     * @param int $maxSlots
     * @return Slot[]
     */
    public function allocateContractedServicePass(Slot $initialSlot, int $maxSlots = 5): array
    {
        assert($initialSlot->isAllocated(), "Initial slot should be allocated");

        $adjacentSlots = [];

        $direction = match (rand(0,1)) {
            0 => 'after',
            1 => 'before',
        };
        /** @var ?Slot $next */
        $next = $this->manager->getClosestFreeSlot($initialSlot);
        if (!$next) {
            $direction = $direction == 'before' ? 'after' : 'before';
            $next = $this->manager->getClosestFreeSlot($initialSlot, direction: $direction);
        }

        if (!$next) throw new \RuntimeException('No more slots to allocate');
        assert($next->isFree());

        while ($next->isFree() && count($adjacentSlots) < $maxSlots) {
            $adjacentSlots[] = $next;

            $offset = match ($direction) {
                'after' => 1,
                'before' => -1,
            };

            $idx = $next->getIndex() + $offset;
            if ($idx < 0 || $idx >= $this->slots->getSize())
                break; // out of bounds

            $next = $this->slots[$next->getIndex() + $offset];

            // Avoid crossing days
            if ($next->getStart()->format(static::DATE_NOTIME) != current($adjacentSlots)->getStart()->format(static::DATE_NOTIME))
                break;
        }

        foreach ($adjacentSlots as $i => $slot) {
            if (!isset($adjacentSlots[$i + 1])) break;

            assert($slot->getPeriod()->touchesWith($adjacentSlots[$i + 1]->getPeriod()), sprintf("Non contiguous slots detected idx={$i},%s", $i+1));
        }

        assert(count($adjacentSlots) <= $maxSlots, sprintf("Returning more than requested slots: requested=%d returned=%d", $maxSlots, count($adjacentSlots)));

        return $adjacentSlots;
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

    public function setManager(?ScheduleManager $manager): self
    {
        $this->manager = $manager;
        return $this;
    }

    //region Validation callbacks

    /**
     * A contracted service cannot be assigned more than 5 hours (=slots) per day.
     *
     * @deprecated not actually used.. but maybe should be used for on-premises tasks?
     */
//    #[Assert\Callback]
    public function validateContractedServicesDailyHours(ExecutionContextInterface $context)
    {
        assert(isset($this->slots), "Slots not inizialized");

        $contractedServices = new \SplObjectStorage();
        $prevSlotTimestamp = 0;
        $prevSlotDay = '';
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */

            assert($prevSlotTimestamp <= $slot->getStart()->getTimestamp(), "Slots not ordered in ascending order");

            // reset tasks when day changes
            if ($prevSlotDay !== $slot->getStart()->format(self::DATE_NOTIME)) {
                // Perform the check
                foreach ($contractedServices as $cs) {
                    if ($contractedServices[$cs] > 5)
                        $context->buildViolation($this->violationMessageContractedServiceExcessDailyHours)
                            ->setParameter('{{ cs }}', $cs)
                            ->setParameter('{{ hours }}', $contractedServices[$cs])
                            ->setParameter('{{ day }}', $prevSlotDay)
                            ->addViolation();
                }

                $contractedServices = new \SplObjectStorage();
            }

            foreach ($slot->getTasks() as $cs) {
                /** @var Task $cs */
                $cs = $cs->getContractedService();
                if (!$contractedServices->contains($cs))
                    $contractedServices[$cs] = 1;
                else
                    $contractedServices[$cs] += 1;
            }

            $prevSlotDay = $slot->getStart()->format(self::DATE_NOTIME);
            $prevSlotTimestamp = $slot->getStart()->getTimestamp();
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
    public function validateNoOverlappingTasks(ExecutionContextInterface $context)
    {
//        $tasks = $this->getTasks()->matching(ScheduleRepository::createSortedTasksCriteria());
//        /** @var array<int, Task> $stack */
//        $taskOverlaps = new \SplObjectStorage(); // <Task, Task[]>
//        $overlapped = $tasks->first();
//        foreach ($tasks as $task) {
//            /** @var Task $task */
//
//            if ($overlapped->getEnd() > $task->getStart() && $overlapped !== $task) {
////                $taskOverlaps[$overlapped] ??= [];
//                $taskOverlaps[$overlapped] = [...($taskOverlaps[$overlapped] ?? []), $task]; // Append overlapping task
//            } else {
//                $overlapped = $task;
//            }
//        }
//
//        // Add violation for overlapping tasks
//        foreach ($taskOverlaps as $task) {
//            foreach ($taskOverlaps[$task] as $overlap) {
//                $context->buildViolation(static::VIOLATION_TASKS_OVERLAPPING)
//                    ->setParameter('{{ task }}', $overlap)
//                    ->setParameter('{{ task_overlapped }}', $task)
//                    ->addViolation();
//            }
//        }

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

    /**
     * Detects whether a task is spread across non-adjacent slots in the same day.
     *
     * Notice that a task is (consultant, recipient, service, on/off-premises).
     */
    #[Assert\Callback]
    public function detectDiscontinuousTasks(ExecutionContextInterface $context)
    {
        $dayTasks = [];
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $hash = $slot->getStart()->format(static::DATE_NOTIME);
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


    public function assertZeroOrOneTaskPerSlot(): void
    {
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            if (count($slot->getTasks()) > 1)
                throw new \LogicException(sprintf("Failed to assert that slot {$slot} contains at most one task: count=%d", count($slot->getTasks())));
        }
    }

    //endregion Validation callbacks

    //region Persisted fields accessors

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(Task $task): self
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks[] = $task;
            $task->setSchedule($this);
        }

        return $this;
    }

    public function removeTask(Task $task): self
    {
        if ($this->tasks->removeElement($task)) {
            // set the owning side to null (unless already changed)
            if ($task->getSchedule() === $this) {
                $task->setSchedule(null);
            }
        }

        return $this;
    }

    public function getFrom(): \DateTimeInterface
    {
        return $this->from;
    }

    public function getTo(): \DateTimeInterface
    {
        return $this->to;
    }

    public function getPeriod(): Period
    {
        if (!isset($this->period))
            $this->period = Period::make($this->getFrom(), $this->getTo(), Precision::HOUR(), Boundaries::EXCLUDE_END());

        return $this->period;
    }

    //endregion Persisted fields accessors

    public function __toString(): string
    {
        return $this->getId() ?? $this->getUuid();
    }
}
