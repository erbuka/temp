<?php


namespace App\Validator\Constraints;


use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class NotItalianHoliday extends Constraint
{
    public string $message = 'Date {{ date }} is a holiday in Italy 🎉';
    public bool $includeDaysBefore;

    public function __construct(
        array $options = null,
        array $groups = null,
        array $payload = null,
        bool $includeDaysBefore = true,
    )
    {
        parent::__construct($options, $groups, $payload);

        $this->includeDaysBefore = $includeDaysBefore;
    }
}
