<?php


namespace App\Validator\Schedule;


use App\Entity\Schedule;
use App\Entity\Task;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class TasksWithinBoundsValidator extends ConstraintValidator
{
    public function validate($value, Constraint $constraint)
    {
        if (!$constraint instanceof TasksWithinBounds)
            throw new UnexpectedTypeException($constraint, TasksWithinBoundsValidator::class);

        if (!$value instanceof Schedule)
            throw new UnexpectedValueException($value, Schedule::class);

        $from = $value->getFrom();
        $to = $value->getTo();

        foreach ($value->getTasks() as $task) {
            /** @var Task $task */
            if ($task->getStart() < $from || $task->getEnd() > $to) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ period }}', "[{$task->getStart()->format(DATE_RFC3339)} - {$task->getEnd()->format(DATE_RFC3339)}]")
                    ->setParameter('{{ schedule_period }}', "[{$from->format(DATE_RFC3339)} - {$to->format(DATE_RFC3339)}]")
                    ->addViolation();
            }
        }
    }
}
