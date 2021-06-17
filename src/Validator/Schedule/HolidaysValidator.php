<?php


namespace App\Validator\Schedule;


use App\Entity\ContractedService;
use App\Entity\Schedule;
use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class HolidaysValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof Holidays)
            throw new UnexpectedTypeException($constraint, Holidays::class);

        if (!$value instanceof Schedule)
            throw new UnexpectedValueException($value, Schedule::class);

        foreach ($value->getTasks() as $task) {
            /** @var Task $task */

        }
    }
}
