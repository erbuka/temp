<?php


namespace App\Validator\Schedule;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;

#[\Attribute(\Attribute::TARGET_CLASS)]
class TasksWithinBounds extends Constraint
{
    public $message = 'Task {{ period }} is outside schedule boundaries {{ schedule_period }}';

    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
