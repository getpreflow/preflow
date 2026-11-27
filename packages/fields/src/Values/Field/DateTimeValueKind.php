<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\DateTimeValue;

/**
 * A "datetime" field's value, shape "datetime": a moment in UTC as "Y-m-d\TH:i:s\Z". toStored()
 * reads a moment with a "Z" or an offset and gives it in UTC; no value is null; text that is no
 * moment is trimmed and passed on for the rule (a form's zone-less local time is the adapter's to
 * parse first). toOutput(): the UTC text, null when none is stored.
 *
 * Named for what it is the value of: Values\DateTimeValue is the value object.
 *
 * @since 0.93.0
 */
final class DateTimeValueKind implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return DateTimeValue::fromStored($input)?->toStored() ?? (is_string($input) ? trim($input) : $input);
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        return DateTimeValue::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'datetime';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('datetime');
    }
}
