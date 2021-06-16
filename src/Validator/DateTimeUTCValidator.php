<?php


namespace App\Validator;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class DateTimeUTCValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof DateTimeUTC) {
            throw new UnexpectedTypeException($constraint, DateTimeUTC::class);
        }

        if ($value === null)
            return;

        if (!$value instanceof \DateTimeInterface) {
            throw new UnexpectedTypeException($value, \DateTime::class);
        }

        if ($value->getOffset() !== 0) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ datetime }}', $value->format(\DateTime::RFC3339))
                ->addViolation();
        }
    }
}
