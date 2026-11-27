<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A date field's stored value (DateFieldType::storedRules()): a value the type reads
 * (DateFieldType::read(): "Y-m-d", "Y-m-d\TH:i", "Y-m-d\TH:i:s" and ISO 8601 with an offset),
 * whether or not the field has "time"; a real date and time (no overflow), nothing around it.
 * Empty (null or "") passes: "required" judges that.
 *
 * @since 0.92.1
 */
final readonly class StoredDate implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '') {
            return true;
        }

        return is_scalar($value) && DateFieldType::read((string) $value) !== null ? true : new Message('validation.regex');
    }
}
