<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class IsInteger implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null) {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false && !is_int($value)) {
            return new Message('validation.integer');
        }
        return true;
    }
}
