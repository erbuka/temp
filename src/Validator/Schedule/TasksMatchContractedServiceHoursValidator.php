<?php


namespace App\Validator\Schedule;


use App\Entity\Schedule;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class TasksMatchContractedServiceHoursValidator extends ConstraintValidator
{
//    private EntityManagerInterface $entityManager;
//
//    public function __construct(EntityManagerInterface $entityManager)
//    {
//        $this->entityManager = $entityManager;
//    }

    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof TasksMatchContractedServiceHours)
            throw new UnexpectedTypeException($constraint, TasksMatchContractedServiceHours::class);

        if (!$value instanceof Schedule)
            throw new UnexpectedValueException($value, Schedule::class);

        // For each contracted service (consultant, recipient, service), check total hours of all tasks

    }
}
