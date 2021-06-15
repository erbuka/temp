<?php


namespace App\Validator\Constraints;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;

#[\Attribute(\Attribute::TARGET_PROPERTY, \Attribute::TARGET_METHOD)]
class BusinessHours extends Constraint
{
    public $message = 'The datetime "{{ date }}" is outside the business hours range from {{ start }} to {{ end }}';

    public \DateTimeInterface $from;
    public \DateTimeInterface $to;

    public function __construct(
        array $options = null,
        string $from = '00:00',
        string $to = '23:59',
        array $groups = null,
        array $payload = null,
    )
    {
        parent::__construct($options, $groups, $payload);

        if (false === $this->from = \DateTimeImmutable::createFromFormat('H:i', $from)) {
            throw new ConstraintDefinitionException(sprintf('Constraint %s requires "from" option to be in H:i format (e.g. 09:45). %s given.', static::class, $from));
        }
        if (false === $this->to = \DateTimeImmutable::createFromFormat('H:i', $to)) {
            throw new ConstraintDefinitionException(sprintf('Constraint %s requires "to" option to be in H:i format (e.g. 09:45). %s given.', static::class, $to));
        }
    }
}
