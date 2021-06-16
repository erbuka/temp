<?php

namespace App\Entity;

use App\Repository\ScheduleRepository;
use App\Slot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use App\Validator as AppAssert;
use App\Validator\Schedule as ScheduleAssert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * @ORM\Entity(repositoryClass=ScheduleRepository::class)
 */
#[ScheduleAssert\TasksWithinBounds]
#[ScheduleAssert\TasksMatchContractedServiceHours]
class Schedule
{
    const HOLIDAY_DATES = [
        // Y-m-d format
        '2021-08-14', // Prefestivo
        '2021-08-15', // Ferragosto

        '2021-10-31', // Prefestivo
        '2021-11-01', // Tutti i santi

        '2021-12-07', // Prefestivo
        '2021-12-08', // Immacolata concezione

        '2021-12-24', // Prefestivo
        '2021-12-25', // Natale
        '2021-12-26', // Santo Stefano

        '2021-12-31', // Prefestivo
        '2022-01-01', // Capodanno

        '2022-01-05', // Prefestivo
        '2022-01-06', // Befana

        '2022-04-16', // Prefestivo
        '2022-04-17', // Pasqua
        '2022-04-18', // Pasquetta

        '2022-04-24', // Prefestivo
        '2022-04-25', // Liberazione

        '2022-04-30', // Prefestivo
        '2022-05-01', // Festa dei lavoratori

        '2022-06-01', // Prefestivo
        '2022-06-02', // Festa della Repubblica

        '2022-08-14', // Prefestivo
        '2022-08-15', // Ferragosto

        '2022-10-31', // Prefestivo
        '2022-11-01', // Tutti i santi

        '2022-12-07', // Prefestivo
        '2022-12-08', // Immacolata concezione

        '2022-12-24', // Prefestivo
        '2022-12-25', // Natale
        '2022-12-26', // Santo Stefano
    ];
    const DATE_NOTIME = 'Y-m-d';
    const DATE_SLOTHASH = 'YmdH';

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
     * @ORM\Column(name="`from`", type="datetime", nullable=false)
     */
    #[Assert\NotNull]
    #[AppAssert\DateTimeUTC]
    private \DateTimeInterface $from;

    /**
     * @ORM\Column(name="`to`", type="datetime", nullable=false)
     */
    #[Assert\NotNull]
    #[AppAssert\DateTimeUTC]
    private \DateTimeInterface $to;

    /**
     * See https://gist.github.com/pylebecq/f844d1f6860241d8b025#:~:text=What's%20the%20difference%20between%20cascade,than%20one%20object%20being%20deleted.
     * For performance considerations, see https://www.doctrine-project.org/projects/doctrine-orm/en/2.9/reference/working-with-associations.html#transitive-persistence-cascade-operations
     * in particular: "Cascade operations require collections and related entities to be fetched into memory"
     *
     * @ORM\OneToMany(targetEntity=Task::class, mappedBy="schedule", orphanRemoval=true)
     * @ORM\OrderBy({"start" = "ASC"})
     * @var Collection<int, Task>
     */
    private Collection $tasks;

    //endregion Persisted fields

    private \SplFixedArray $slots;
    /** @var array<string, Slot> e.g. '2021020318' => Slot */
    private array $dayHourSlotMap;
    private Consultant $consultant;
    private Period $period;

    /**
     * Invoked only when creating new entities inside the app.
     * Never invoked by Doctrine when retrieving objcts from the database.
     */
    public function __construct(\DateTimeInterface $fromDay, \DateTimeInterface $toDay)
    {
        $this->tasks = new ArrayCollection();
        $this->uuid = Uuid::v4();
        $this->period = Period::make($fromDay, $toDay, Precision::DAY(), Boundaries::EXCLUDE_NONE());
        $this->from = $this->period->start();
        $this->to = $this->period->end();

        $this->generateSlots();
    }

    private function generateSlots()
    {
        assert(!isset($this->slots), "Refusing to overwrite existing slots in Schedule");

        $eligibleDays = [];
        $slotsMap = [];

        foreach ($this->period as $day) {
            /** @var \DateTimeImmutable $day */

            if (in_array($day->format(static::DATE_NOTIME), static::HOLIDAY_DATES)) {
//                $this->output->writeln(sprintf('- %s skipped because it is an holiday', $date->format(self::DATE_NOTIME)));
                continue;
            }

            if (in_array($weekday = $day->format('w'), [6,0])) {
//                $this->output->writeln(sprintf('- %2$s skipped because it is %1$s', $weekday == 6 ? 'Saturday' : 'Sunday', $date->format(self::DATE_NOTIME)));
                continue;
            }

            $dayStart = \DateTime::createFromImmutable($day);
            $dayStart->setTime(8, 0);
            $dayEnd = (clone $dayStart)->setTime(18, 0);

            $businessHours = Period::make($dayStart, $dayEnd, Precision::HOUR(), Boundaries::EXCLUDE_END());
            foreach ($businessHours as $hour) {
                /** @var \DateTimeImmutable $hour */
                $slot = new Slot(count($eligibleDays), $hour);
                $hash = $slot->getStart()->format(static::DATE_SLOTHASH);
                assert(!isset($slotsMap[$hash]), "Multiple slots have the same hash {$hash}");

                $slotsMap[$hash] = $slot;
                $eligibleDays[] = $slot;

                assert($slot->getIndex() === array_search($slot, $eligibleDays, strict: true));
            }
        }

        $this->slots = \SplFixedArray::fromArray($eligibleDays);
        $this->dayHourSlotMap = $slotsMap;
    }

    private function getSlots(): \SplFixedArray
    {
        if (!$this->slots)
            $this->generateSlots();

        return $this->slots;
    }

    /**
     * @param \DateTimeInterface|null $after
     * @param \DateTimeInterface|null $before
     * @return ?Slot
     */
    public function getRandomFreeSlot(\DateTimeInterface $after = null, \DateTimeInterface $before = null): ?Slot
    {
        $slots = $this->getSlots();

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

    public function getClosestFreeSlot(Slot $slot, string $direction = 'both'): ?Slot
    {
        $slotIndex = $slot->getIndex();
        $slots = $this->getSlots();
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

    public function getStats(): string
    {
        $slots = $this->getSlots();
        $allocatedSlots = 0;
        $slotsCount = $slots->getSize(); // ::count() and count() are equivalent to ::getSize()

        foreach ($slots as $slot) {
            /** @var Slot $slot */
            if ($slot->isAllocated())
                $allocatedSlots++;
        }

        return sprintf("id=%s period=%s, slots=%d, allocated_slots=%d tasks=%d",
    $this->id ?? $this->getUuid()->toRfc4122(),
            $this->period->asString(),
            $slotsCount,
            $allocatedSlots,
            count($this->tasks)
        );
    }

    /**
     * A task is a contiguous amount of time that will start at some slot and end in another.
     */
    public function loadTasksIntoSlots()
    {
        $tasks = $this->getTasks();

        // hash each slot by datetime Ymd-h
        foreach ($tasks as $task) {
            /** @var Task $task */
            $this->loadTaskIntoSlot($task);
        }
    }

    protected function loadTaskIntoSlot(Task $task)
    {
        // A task is considered to belong to a slot iff its starting
        $period = Period::make($task->getStart(), $task->getEnd(), Precision::HOUR(), Boundaries::EXCLUDE_END());

        foreach ($period as $hour) {
            $key = $hour->format(static::DATE_SLOTHASH);
            if (!isset($this->dayHourSlotMap[$key]))
                throw new \RuntimeException("Task {$period->asString()} is outside this schedule boundaries {$this->period->asString()}");
            assert($this->dayHourSlotMap[$key] instanceof Slot, "Map does not return a slot");

            $this->dayHourSlotMap[$key]->addTask($task);
        }
    }

    /**
     * and could deal with straight tasks considering the fact that
     * loading tasks (from the database) into slots is the same problem.
     *
     * Sets $this as the owning schedule of each merged task.
     */
    public function merge(Schedule ...$sources)
    {
        foreach ($sources as $schedule) {
            foreach ($schedule->getTasks() as $task) {
                /** @var Task $task */
                $this->loadTaskIntoSlot($task);
                $task->setSchedule($this);
                $this->addTask($task);
            }
        }
    }

    public function getSlot(Task $task): Slot
    {

    }

    /**
     * @param Slot $initialSlot
     * @param ContractedService $contractedServiceService
     * @param int $maxSlots
     * @return Slot[]
     */
    public function allocateContractedServicePass(Slot $initialSlot, ContractedService $contractedServiceService, int $maxSlots = 5): array
    {
        assert($initialSlot->isAllocated(), "Initial slot should be allocated");

        $adjacentSlots = [];

        $direction = match (rand(0,1)) {
            0 => 'after',
            1 => 'before',
        };
        /** @var ?Slot $next */
        $next = $this->getClosestFreeSlot($initialSlot, $direction);
        if (!$next) {
            $direction = $direction == 'before' ? 'after' : 'before';
            $next = $this->getClosestFreeSlot($initialSlot, $direction);
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

    public function getConsultantSchedule(Consultant $consultant): Schedule
    {
        throw new \RuntimeException('Not implemented');
    }

    /**
     * Should use only tasks.
     * Should not depend on EntityManager or other services. If this is needed, refactor to external constraint.
     * Advantage of using callbacks: access to private members.
     *
     * @param Schedule $schedule
     * @param ExecutionContextInterface $context
     * @param $payload
     */
    #[Assert\Callback]
    public static function validate(Schedule $schedule, ExecutionContextInterface $context, $payload)
    {
        // needs tasks, access to private state and methods

        // Validator constraint does not access private state
    }

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
     * @return ArrayCollection<Task>
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

    //endregion Persisted fields accessors
}
