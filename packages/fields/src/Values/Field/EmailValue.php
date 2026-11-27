<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Email;

/**
 * An "email" field's value, shape "string": the address as trimmed text; blank text is "" (a text
 * field, as a string field keeps it and as Folio's form stored it before 0.93), no value (null, an
 * empty list) is null, other than text passes through for the rule. Output: "" reads as null.
 *
 * @since 0.93.0
 */
final class EmailValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if ($input === null || $input === []) {
            return null;
        }
        if (is_string($input) && trim($input) === '') {
            return '';
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
