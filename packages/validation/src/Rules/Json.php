<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * Text that is valid JSON (json_validate(): any JSON value, such as {"a": 1}, [1, 2] or "text").
 * An empty value passes ("required" decides).
 *
 * @since 0.91.0
 */
final class Json implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && json_validate($value) ? true : new Message('validation.json');
    }
}
