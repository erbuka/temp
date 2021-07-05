<?php


namespace App\Validator\Schedule;


use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class MatchContractedServiceHours extends Constraint
{
    public $message = 'The {{ actual }} {{ type }} hours scheduled do not match contracted service {{ contracted_service }} hours of {{ expected }}';

    public bool $onPremises;
    public bool $remote;

    public function __construct(bool $onPremises = true, bool $remote = true, $options = null, array $groups = null, $payload = null)
    {
        parent::__construct($options, $groups, $payload);

        $this->onPremises = $onPremises;
        $this->remote = $remote;
    }

    public function getTargets()
    {
        return self::CLASS_CONSTRAINT;
    }
}
