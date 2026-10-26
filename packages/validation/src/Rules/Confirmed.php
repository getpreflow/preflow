<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

final class Confirmed implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        $confirmation = $context->getValue($context->field . '_confirmation');
        if ($value !== $confirmation) {
            return new Message('validation.confirmed');
        }
        return true;
    }
}
