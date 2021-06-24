<?php


namespace App\Validator\Schedule;


use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class MatchContractedServiceHours  extends Constraint
{
    public $message = 'The {{ actual }} {{ type }} hours scheduled do not match contracted service {{ contracted_service }} hours of {{ expected }}';

    public bool $onPremisesOnly;

    public function __construct(bool $onPremisesOnly = false, $options = null, array $groups = null, $payload = null)
    {
        parent::__construct($options, $groups, $payload);

        $this->onPremisesOnly = $onPremisesOnly;
    }

    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
