<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "boolean" field's value, shape "boolean": "1", "true", "on" and 1 are true, "0", "false" and
 * 0 false (text in any case), empty ("") is null; anything else passes through.
 *
 * @since 0.93.0
 */
final class BooleanValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return self::bool($input);
    }

    /** true or false; null without a value and for anything else stored ("yes", 2, a list). */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?bool
    {
        $bool = self::bool($stored);

        return is_bool($bool) ? $bool : null;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'boolean';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('boolean');
    }

    private static function bool(mixed $value): mixed
    {
        return match (is_string($value) ? strtolower($value) : $value) {
            '1', 'true', 'on', 1 => true,
            '0', 'false', 0 => false,
            '' => null,
            default => $value,
        };
    }
}
