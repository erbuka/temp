<?php


namespace App;


use App\Entity\Schedule;
use Spatie\Period\Period;
use Throwable;

class NoMatchingSlotsAvailableException extends \RuntimeException
{
    public function __construct(Schedule $schedule, Period $period = null, Throwable $previous = null)
    {
        if ($period)
            parent::__construct("Schedule {$schedule} does not contain slots matching the search criteria in period {$period->asString()}", 0, $previous);
        else
            parent::__construct("Schedule {$schedule} does not contain slots matching the search criteria", 0, $previous);
    }
}
