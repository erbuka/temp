<?php


namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class DateTimeUTC extends Constraint
{
    /** @var string  */
    public $message = 'The DateTime "{{ datetime }}" is not UTC';
}
