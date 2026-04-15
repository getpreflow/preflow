<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Between implements ValidationRule
{
    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }
        $size = is_numeric($value) ? $value : (is_string($value) ? mb_strlen($value) : 0);
        if ($size < $this->min || $size > $this->max) {
            return "Must be between {$this->min} and {$this->max}.";
        }
        return true;
    }
}
