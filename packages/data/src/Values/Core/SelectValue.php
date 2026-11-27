<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "select" field's value: the chosen option's key as text, shape "string"; nothing chosen ("")
 * is null. A number given is its text (an option key "2"); anything else passes through for the
 * core's option check (SelectRules).
 *
 * @since 0.93.0
 */
final class SelectValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return self::key($input);
    }

    /** The option's key as text; null without a value and for anything else stored (a list, true). */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        $key = self::key($stored);

        return is_string($key) ? $key : null;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'string';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('string');
    }

    private static function key(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $value === '' ? null : $value;
    }
}
