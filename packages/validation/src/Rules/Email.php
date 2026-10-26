<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Email implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return new Message('validation.email');
        }
        return true;
    }
}
