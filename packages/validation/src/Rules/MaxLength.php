<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class MaxLength implements ValidationRule
{
    public function __construct(
        private readonly int|float $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null) {
            return true;
        }
        if (is_numeric($value)) {
            if ($value > $this->max) {
                return new Message('validation.max.number', ['max' => (string) $this->max]);
            }
            return true;
        }
        if (is_string($value) && mb_strlen($value) > $this->max) {
            return new Message('validation.max.string', ['max' => (string) $this->max]);
        }
        return true;
    }
}
