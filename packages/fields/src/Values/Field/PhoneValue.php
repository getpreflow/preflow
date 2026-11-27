<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Phone;

/**
 * A "phone" field's value, shape "phone": the stored object {as_typed, e164}.
 *
 * toStored(): the object, JSON text of it, or the number as plain text (read as typed, with its
 * international form when it starts with + or 00); no value is null; an object without a number is
 * passed on for the rule. toOutput(): the object, null when no number is stored.
 *
 * @since 0.93.0
 */
final class PhoneValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return Phone::fromStored($input)?->toStored() ?? Link::decode($input) ?? $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return Phone::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'phone';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('phone');
    }
}
