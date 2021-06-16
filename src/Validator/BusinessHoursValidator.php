<?php


namespace App\Validator;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class BusinessHoursValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof BusinessHours)
            throw new UnexpectedTypeException($constraint, BusinessHours::class);

        if ($value === null)
            return;

        if (!$value instanceof \DateTimeInterface)
            throw new UnexpectedValueException($value, "DateTimeInterface");

        $dt = \DateTime::createFromInterface($value);
        $dt->setTime((int)$constraint->from->format('H'), (int)$constraint->from->format('i'));

        if ($value < $dt) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ date }}', $value->format(DATE_RFC3339))
                ->addViolation();
        }

        $dt->setTime((int)$constraint->to->format('H'), (int)$constraint->to->format('i'));
        if ($value > $dt) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ date }}', $value->format(DATE_RFC3339))
                ->addViolation();
        }
    }
}
