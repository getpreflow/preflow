<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * Marker rule: when present in a rule chain and the value is null,
 * the Validator stops the chain early (passes). This rule itself always passes.
 */
final class Nullable implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        return true;
    }
}
