<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Confirmed implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string
    {
        $confirmation = $context->getValue($context->field . '_confirmation');
        if ($value !== $confirmation) {
            return 'Confirmation does not match.';
        }
        return true;
    }
}
