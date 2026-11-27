<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Color;

/**
 * A "color" field's value, shape "color": "#rrggbb" or "#rrggbbaa" in lower case. toStored() reads
 * "#abc" and "ABC123" forms; no value is null; text that is no colour is trimmed and passed on for
 * the rule. toOutput(): the colour, null when none is stored.
 *
 * @since 0.93.0
 */
final class ColorValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return Color::fromStored($input)?->toStored() ?? (is_string($input) ? trim($input) : $input);
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?string
    {
        return Color::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'color';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('color');
    }
}
