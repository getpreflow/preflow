<?php

declare(strict_types=1);

namespace Preflow\Data\Values;

use Preflow\Data\TypeFieldDefinition;

/**
 * The value of a field type that registered none (FieldKinds::valueOf()): read and written
 * unchanged, shape "raw".
 *
 * @since 0.93.0
 */
final class RawValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return $stored;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'raw';
    }

    public function shapes(): array
    {
        return ['raw' => CoreValues::SHAPES['raw']];
    }
}
