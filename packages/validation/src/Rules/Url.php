<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Url implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return 'Must be a valid URL.';
        }
        return true;
    }
}
