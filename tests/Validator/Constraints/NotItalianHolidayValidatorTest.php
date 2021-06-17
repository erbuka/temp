<?php


namespace App\Tests\Validator\Constraints;


use App\Validator\Constraints\NotItalianHoliday;
use App\Validator\Constraints\NotItalianHolidayValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

class NotItalianHolidayValidatorTest extends ConstraintValidatorTestCase
{
    /**
     * @dataProvider holidayProvider
     */
    public function testHolidays(\DateTimeInterface $date) {
        $constraint = new NotItalianHoliday(includeDaysBefore: false);

        $this->validator->validate($date, $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ date }}', $date->format(DATE_RFC3339))
            ->setInvalidValue($date)
            ->assertRaised();
    }

    /**
     * @dataProvider prefestivoProvider
     */
    public function testExcludePrefestivi(\DateTimeInterface $date) {
        $constraint = new NotItalianHoliday(includeDaysBefore: false);

        $this->validator->validate($date, $constraint);
        $this->assertNoViolation();
    }

    /**
     * @dataProvider prefestivoProvider
     */
    public function testIncludePrefestivi(\DateTimeInterface $date) {
        $constraint = new NotItalianHoliday(includeDaysBefore: true);

        $this->validator->validate($date, $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ date }}', $date->format(DATE_RFC3339))
            ->setInvalidValue($date)
            ->assertRaised();
    }

    /**
     * @dataProvider businessDayProvider
     */
    public function testBusinessDays(\DateTimeInterface $date) {
        $constraint = new NotItalianHoliday(includeDaysBefore: true);

        $this->validator->validate($date, $constraint);

        $this->assertNoViolation();
    }

    /**
     * @dataProvider easterProvider
     */
    public function testEaster(\DateTimeInterface $date) {
        $constraint = new NotItalianHoliday();

        $this->validator->validate($date, $constraint);

        $this->buildViolation($constraint->message)
            ->setParameter('{{ date }}', $date->format(DATE_RFC3339))
            ->setInvalidValue($date)
            ->assertRaised();
    }

    protected function createValidator()
    {
        return new NotItalianHolidayValidator();
    }

    //region Providers

    public function easterProvider() {
        $fixtures = [
            '2021-04-04' => [new \DateTime('2021-04-04')],
            '2022-04-17' => [new \DateTime('2022-04-17')],
            '2022-04-09' => [new \DateTime('2023-04-09')],
            '2022-03-31' => [new \DateTime('2024-03-31')],
        ];

        return $fixtures;
    }

    public function holidayProvider() {
        $fixtures = [];
        foreach (NotItalianHolidayValidator::HOLIDAY_DATES as $holiday) {
            $date = new \DateTimeImmutable(rand(2020, (int)date('Y')) . "-{$holiday}");
            $fixtures[$date->format(DATE_RFC3339)] = [$date];
        }

        return $fixtures;

    }

    public function businessDayProvider() {
        $year = rand(2020, 2050);

        $fixtures = [];
        for ($i=0; $i < 366; $i++) {
            $date = (new \DateTime())
                ->setDate($year, 1, 1)
                ->add(new \DateInterval("P{$i}D"));

            $dateValidatorFormat = $date->format(NotItalianHolidayValidator::DATE_FORMAT);
            if (in_array($dateValidatorFormat, NotItalianHolidayValidator::HOLIDAY_DATES) ||
                in_array($dateValidatorFormat, NotItalianHolidayValidator::PREFESTIVI)) {
                    continue;
                }

            if (in_array((int)$date->format('m'), [3,4]))
                continue; // skip March and April altogether because there is Easter somewhere in between

            $fixtures[$date->format(DATE_RFC3339)] = [$date];
        }

        return $fixtures;
    }

    public function prefestivoProvider() {
        $fixtures = [];
        foreach (NotItalianHolidayValidator::PREFESTIVI as $prefestivo) {
            $date = new \DateTimeImmutable(rand(2020, (int)date('Y')) . "-{$prefestivo}");
            $fixtures[$date->format(DATE_RFC3339)] = [$date];
        }

        return $fixtures;
    }

    //endregion Providers
}
