<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Numeric implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }
        if (!is_numeric($value)) {
            return 'Must be a number.';
        }
        return true;
    }
}
