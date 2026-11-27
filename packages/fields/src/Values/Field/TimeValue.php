<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\TimeOfDay;

/**
 * A "time" field's value, shape "time": "HH:MM". toStored() reads "9:30" and "09:30:00" too; no
 * value is null; text that is no time is trimmed and passed on for the rule. toOutput(): the
 * "HH:MM" text, null when none is stored.
 *
 * @since 0.93.0
 */
final class TimeValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return TimeOfDay::parse($input)?->toStored() ?? (is_string($input) ? trim($input) : $input);
    }

    /** "HH:MM"; a time stored as "9:30" or "09:30:00" (older data) reads as its time, as the form reads it; null for anything else. */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        return TimeOfDay::parse($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'time';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('time');
    }
}
