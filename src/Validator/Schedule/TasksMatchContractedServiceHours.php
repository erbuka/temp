<?php


namespace App\Validator\Schedule;


use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class TasksMatchContractedServiceHours  extends Constraint
{
    public $message = 'Task {{ period }} is outside schedule boundaries {{ schedule_period }}';

    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
