<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Code;

/**
 * A "code" field's value, shape "string": the code as it is written (not trimmed: indentation is
 * code); an empty text is null; other than text passes through for the rule.
 *
 * @since 0.93.0
 */
final class CodeValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if ($input === null || $input === '' || $input === []) {
            return null;
        }

        return Code::fromStored($input)?->toStored() ?? $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        return Code::fromStored($stored)?->toStored();
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
