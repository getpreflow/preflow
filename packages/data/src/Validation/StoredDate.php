<?php

declare(strict_types=1);

namespace Preflow\Data\Validation;

use Preflow\Data\Values\Core\DateValue;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A date field's stored value (the core's date rule, CoreValues): a value the type reads
 * (DateValue::read(): "Y-m-d", "Y-m-d\TH:i", "Y-m-d\TH:i:s" and ISO 8601 with an offset),
 * whether or not the field has "time"; a real date and time (no overflow), nothing around it.
 * Empty (null or "") passes: "required" judges that.
 *
 * Moved from the admin package (0.92.1), where the old name stays as an alias until 0.95.
 *
 * @since 0.93.0
 */
final readonly class StoredDate implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_scalar($value) && DateValue::read((string) $value) !== null ? true : new Message('validation.regex');
    }
}
