<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class MaxLength implements ValidationRule
{
    public function __construct(
        private readonly int|float $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }
        if (is_numeric($value)) {
            if ($value > $this->max) {
                return "Must be at most {$this->max}.";
            }
            return true;
        }
        if (is_string($value) && mb_strlen($value) > $this->max) {
            return "Must be at most {$this->max} characters.";
        }
        return true;
    }
}
