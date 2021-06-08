<?php


namespace App\Tests;


use PHPUnit\Framework\TestCase;
use Spatie\Period\Period;
use Spatie\Period\Precision;

class PeriodTest extends TestCase
{
    public function testBoundariesTruncation(\DateTimeInterface $start = null, \DateTimeInterface $end = null, Precision $precision = null) {

        if (!$start) $start = \DateTimeImmutable::createFromFormat(DATE_ATOM, '2021-10-01T08:30:45Z');
        if (!$end) $end = \DateTimeImmutable::createFromFormat(DATE_ATOM, '2021-10-05T17:54:37Z');
        if (!$precision) $precision = Precision::DAY();

        $period = Period::make($start, $end, $precision);

        $this->assertTrue($period->start() == $start->setTime(0, 0), 'Start date not truncated');
    }

    /**
     * Boundaries are truncated, however comparison with other dates performed without truncating the comparing date.
     *
     * @param \DateTimeInterface|null $boundary
     * @param \DateTimeInterface|null $dateTime
     * @param Precision|null $precision
     * @throws \Exception
     */
    public function testStartBoundaryComparison(\DateTimeInterface $boundary = null, \DateTimeInterface $dateTime = null, Precision $precision = null) {
        if (!$boundary) $boundary = \DateTimeImmutable::createFromFormat(DATE_ATOM, '2021-10-01T08:30:45Z');
        if (!$dateTime) $dateTime = $boundary->add(new \DateInterval('PT1S'));
        if (!$precision) $precision = Precision::DAY();

        if ($precision->equals(Precision::SECOND())) {
            throw new \Exception('Cannot use Precision::SECOND');
        }

        $period = Period::make($boundary, $boundary->add(new \DateInterval('P5D')), $precision);
        $this->assertTrue($period->startsBeforeOrAt($dateTime));
        $this->assertFalse($period->startsAfterOrAt($dateTime));
    }
}
