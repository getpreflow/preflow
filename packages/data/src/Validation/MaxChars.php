<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The value is at most $max characters long (validation.max.string), measured as text with
 * mb_strlen, also for a number-like value ("2024" is 4 characters, never the number 2024). The
 * length limit of a bounded column; DataManager::validateStorable() measures the same way.
 */
final class MaxChars implements ValidationRule
{
    public function __construct(
        private readonly int $max,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if (is_scalar($value) && mb_strlen((string) $value) > $this->max) {
            return new Message('validation.max.string', ['max' => (string) $this->max]);
        }

        return true;
    }
}
