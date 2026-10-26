<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Between implements ValidationRule
{
    public function __construct(
        private readonly int|float $min,
        private readonly int|float $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null) {
            return true;
        }
        $size = is_numeric($value) ? $value : (is_string($value) ? mb_strlen($value) : 0);
        if ($size < $this->min || $size > $this->max) {
            return new Message('validation.between', ['min' => (string) $this->min, 'max' => (string) $this->max]);
        }
        return true;
    }
}
