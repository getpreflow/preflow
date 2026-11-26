<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\Values\TimeOfDay;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A time field's value is a time of day as "HH:MM" (00:00 to 23:59). An empty value passes
 * ("required" decides). Registered for every "time" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidTime implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }

        return TimeOfDay::fromStored($value) !== null ? true : new Message('validation.time');
    }
}
