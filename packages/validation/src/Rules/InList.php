<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class InList implements ValidationRule
{
    /** @param list<string> $values */
    public function __construct(
        private readonly array $values,
    ) {}

    public function validate(mixed $value, ValidationContext $context): true|string
    {
        if ($value === null) {
            return true;
        }
        if (!in_array($value, $this->values, false)) {
            $list = implode(', ', $this->values);
            return "Must be one of: {$list}.";
        }
        return true;
    }
}
