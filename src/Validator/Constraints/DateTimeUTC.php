<?php


namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;


/**
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
class DateTimeUTC extends Constraint
{
    /** @var string  */
    public $message = 'The DateTime "{{ datetime }}" is not UTC';
}