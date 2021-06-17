<?php


namespace App\Validator\Schedule;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Holidays extends Constraint
{
    public $message = 'Task {{ task }} is scheduled on a holiday {{ holiday }}';

    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
