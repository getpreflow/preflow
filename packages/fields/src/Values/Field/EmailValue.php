<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Email;

/**
 * An "email" field's value, shape "string": the address as trimmed text; no value is null, other
 * than text passes through for the rule.
 *
 * @since 0.93.0
 */
final class EmailValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return Email::fromStored($input)?->toStored() ?? $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        return Email::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'string';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('string');
    }
}
