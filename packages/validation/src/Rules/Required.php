<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Required implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return new Message('validation.required');
        }
        return true;
    }
}
