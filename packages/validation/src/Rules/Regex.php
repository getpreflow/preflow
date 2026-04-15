<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Regex implements ValidationRule
{
    public function __construct(
        private readonly string $pattern,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (!preg_match($this->pattern, (string) $value)) {
            return 'Format is invalid.';
        }
        return true;
    }
}
