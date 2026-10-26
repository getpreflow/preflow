<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class InList implements ValidationRule
{
    /** @param list<string> $values */
    public function __construct(
        private readonly array $values,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null) {
            return true;
        }
        if (!in_array($value, $this->values, false)) {
            return new Message('validation.in', ['values' => implode(', ', $this->values)]);
        }
        return true;
    }
}
