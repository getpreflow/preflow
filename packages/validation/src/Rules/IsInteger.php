<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class IsInteger implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false && !is_int($value)) {
            return 'Must be an integer.';
        }
        return true;
    }
}
