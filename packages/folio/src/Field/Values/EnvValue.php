<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Values;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * An "env" field's value (0.93): the field names an environment variable ("env": "NAME") for a
 * secret in settings; nothing is taken from an input or stored, and nothing is read out (the
 * secret never leaves the server). Shape "raw": always null.
 *
 * @since 0.93.0
 */
final class EnvValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return null;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return null;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'raw';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('raw');
    }
}
