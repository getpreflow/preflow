<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class MinLength implements ValidationRule
{
    public function __construct(
        private readonly int|float $min,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }
        if (is_numeric($value)) {
            if ($value < $this->min) {
                return "Must be at least {$this->min}.";
            }
            return true;
        }
        if (is_string($value) && mb_strlen($value) < $this->min) {
            return "Must be at least {$this->min} characters.";
        }
        return true;
    }
}
