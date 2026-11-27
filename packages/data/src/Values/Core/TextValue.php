<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "string" or "text" field's value: text, shape "string". A number given or stored is its
 * text; null stays null (no value); anything else passes through.
 *
 * @since 0.93.0
 */
final class TextValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return is_int($input) || is_float($input) ? (string) $input : $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return is_int($stored) || is_float($stored) ? (string) $stored : $stored;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'string';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('string');
    }
}
