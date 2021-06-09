<?php


namespace App;

use App\Entity\Consultant;
use Spatie\Period\Period;
use Spatie\Period\Precision;
use Doctrine\ORM\Mapping as ORM;
use App\Validator\Constraints as CustomAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Represent a Consultant schedule
 *
 * N.B. doctrine does not call the constructor when loading the object from the database.
 * @deprecated
 */
class Schedule
{
    private \SplFixedArray $slots;

    private Consultant $consultant;
    private Period $period;

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

        '2022-01-06', // Prefestivo
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

    public function __construct(\DateTimeInterface $fromDay, \DateTimeInterface $toDay)
    {
        $this->period = Period::make($fromDay, $toDay, Precision::DAY());

        $this->generateSlots();
    }

    private function generateSlots()
    {
        $eligibleDays = [];

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

            $businessHours = Period::make($dayStart, $dayEnd, Precision::HOUR());
            foreach ($businessHours as $hour) {
                /** @var \DateTimeImmutable $hour */
                $eligibleDays[] = new Slot($hour);
            }
        }

        $this->slots = \SplFixedArray::fromArray($eligibleDays);
    }

    /**
     * @return Slot[]
     */
    public function getSlots(): \SplFixedArray
    {
        return $this->slots; // by reference
    }

    /**
     * @param \DateTimeInterface|null $after
     * @param \DateTimeInterface|null $before
     * @return ?Slot
     */
    public function getRandomFreeSlot(\DateTimeInterface $after = null, \DateTimeInterface $before = null): ?Slot
    {
        // More efficient way: randomly pick a slot, then move backwords or forwards (random) and return the first free slot.

        $index = rand(0, $this->slots->getSize() - 1);
        /** @var Slot $slot */
        $slot = $this->slots[$index];

        if ($slot->isFree())
            return $slot;

        $direction = match (rand(0, 2)) {
            0 => 'before',
            1 => 'after',
            default => 'both'
        };

        return $this->getClosestFreeSlot($index, $direction);
    }

    public function getClosestFreeSlot(int $slotIndex, string $direction = 'both'): ?Slot
    {
        assert(-1 < $slotIndex && $slotIndex < $this->slots->getSize(), "Given slot index {$slotIndex} out of bounds [0, {$this->slots->getSize()}]");

        $closestBefore = $closestAfter = null;
        $closestBeforeDistance = $closestAfterDistance = INF;

        // find closest before, including the initial slot
        if ($direction == 'both' || $direction == 'before') {
            for ($offset = 0, $idx = $slotIndex; $idx >= 0; $idx =  --$offset + $slotIndex) {
                /** @var Slot $slot */
                $slot = $this->slots[$idx];

                if (!$slot->isAllocated()) {
                    $closestBefore = $slot;
                    $closestBeforeDistance = $offset;
                    break;
                }
            }
        }

        // find closest after, including the initial slot
        if ($direction == 'both' || $direction == 'after') {
            for ($offset = 0, $idx = $slotIndex; $idx < $this->slots->getSize(); $idx = ++$offset + $slotIndex) {
                /** @var Slot $slot */
                $slot = $this->slots[$idx];

                if (!$slot->isAllocated()) {
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
        $allocatedSlots = 0;
        $slotsCount = $this->slots->getSize(); // ::count() and count() are equivalent to ::getSize()

        foreach ($this->slots as $slot) {
            /** @var Slot $slot */
            if ($slot->isAllocated())
                $allocatedSlots++;
        }

        return sprintf("Schedule period=%s, slots=%d, allocated_slots=%d",
            $this->period->asString(),
            $slotsCount,
            $allocatedSlots,
        );
    }

    public function loadTasksIntoSlots()
    {
        throw new \RuntimeException('Not Implemented');
    }
}
