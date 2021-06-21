<?php


namespace App;


use App\Entity\Schedule;
use App\Entity\Task;
use App\Repository\ScheduleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Spatie\Period\Boundaries;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use App\Validator\Constraints as AppAssert;

class ScheduleManager
{
    const DATE_NOTIME = 'Y-m-d';
    const DATE_SLOTHASH = 'YmdH';

    private EntityManagerInterface $entityManager;
    private Schedule $schedule;
    private \SplFixedArray $slots;
    /** @var array<string, Slot> e.g. '2021020318' => Slot */
    private array $dayHourSlotMap;
    private Period $period;

    public function __construct(Schedule $schedule, EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
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
        assert(!isset($this->slots), "Refusing to overwrite existing slots in Schedule");

        $eligibleDays = [];
        $slotsMap = [];

        foreach ($this->period as $day) {
            /** @var \DateTimeImmutable $day */

            if (AppAssert\NotItalianHolidayValidator::isItalianHoliday($day, includePrefestivi: true)) {
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


    /**
     * This is called by the factory to ensure the manager is in sync with the actual schedule tasks.
     * Notice that nothing prevents bogus code to directly add tasks to a schedule without using the manager.
     *
     * In general, this method is used by the factory to partially reset the manager under the assumption
     * that variants (tasks, ...) have changed between ::createScheduleManager() invocations.
     *
     * N.B. Managers are cached to avoid recreating the slots which are based on the Schedule invariant 'from' and 'to' properties.
     */
    public function reloadTasks()
    {
        // Empty all slots
        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            $slot->empty();
        }

        // Load tasks
        $tasks = $this->schedule->getTasks()->matching(ScheduleRepository::createTasksSortedByStartCriteria());
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

    // Validation callbacks


    //endregion Validation callbacks
}
