<?php


namespace App\Validator\Constraints;


use App\Entity\ContractedService;
use App\Entity\Schedule;
use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class NotItalianHolidayValidator extends ConstraintValidator
{
    const DATE_FORMAT = 'm-d';
    const HOLIDAY_DATES = [
        // m-d format.
        '01-01', // Capodanno
        '01-06', // Epifania
        '04-25', // Liberazione
        '05-01', // Festa dei lavoratori
        '06-02', // Festa della Repubblica
        '08-15', // Ferragosto
        '11-01', // Tutti i santi
        '12-08', // Immacolata concezione
        '12-25', // Natale
        '12-26', // Santo Stefano
    ];
    const PREFESTIVI = [
        // Y-m-d format
        '01-05', // Epifania
        '04-24', // Liberazione
        '04-30', // Festa dei lavoratori
        '06-01', // Festa della Repubblica
        '08-14', // Ferragosto
        '10-31', // Tutti i santi
        '12-07', // Immacolata concezione
        '12-24', // Natale
        '12-31', // Capodanno
    ];

    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof NotItalianHoliday)
            throw new UnexpectedTypeException($constraint, NotItalianHoliday::class);

        if (null === $value)
            return;

        if (!$value instanceof \DateTimeInterface)
            throw new UnexpectedValueException($value, \DateTimeInterface::class);

        if (static::isItalianHoliday($value, $constraint->includeDaysBefore)) {
            $this->context->buildViolation($constraint->message)
            ->setParameter('{{ date }}', $value->format(DATE_RFC3339))
            ->setInvalidValue($value)
            ->addViolation();
        }
    }

    /**
     * @param int|null $year
     * @param bool $includePrefestivi
     * @return array<int, string>
     * @throws \Exception
     */
    public static function getItalianHolidays(bool $includePrefestivi, int $year = null): array
    {
        static $byYear = [];
        static $byYearInclPrefestivi = [];

        $year ??= (int)date('Y');

        if ($includePrefestivi)
            $holidays = &$byYear;
        else
            $holidays = &$byYearInclPrefestivi;

        if (!isset($holidays[$year])) {
            $easterDay = (new \DateTimeImmutable("{$year}-03-21"))
                ->add(new \DateInterval("P" . easter_days($year) . "D"));

            $days = [
                ...static::HOLIDAY_DATES,
                $easterDay->format(static::DATE_FORMAT),
                $easterDay->add(new \DateInterval('P1D'))->format(static::DATE_FORMAT),
            ];

            if ($includePrefestivi) {
                $days = [
                    ...$days,
                    ...static::PREFESTIVI,
                    $easterDay->sub(new \DateInterval('P1D'))->format(static::DATE_FORMAT),
                ];
            }

            $holidays[$year] = $days;
        }

        return $holidays[$year];
    }

    private static function isItalianHoliday(\DateTimeInterface $date, bool $includePrefestivi): bool
    {
        $holidays = static::getItalianHolidays($includePrefestivi, (int)$date->format('Y'));
        return in_array($date->format(static::DATE_FORMAT), $holidays);
    }
}
