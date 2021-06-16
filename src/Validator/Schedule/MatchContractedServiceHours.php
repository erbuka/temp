<?php


namespace App\Validator\Schedule;


use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class MatchContractedServiceHours  extends Constraint
{
    public $message = 'The {{ actual }} {{ type }} hours scheduled do not match contracted service {{ contracted_service }} hours of {{ expected }}';

    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
