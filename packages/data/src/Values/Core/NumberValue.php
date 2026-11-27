<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "number" field's value, shape "number".
 *
 * Stored as given: a number, or numeric text, as the form posts it (Folio 0.92 stored it so in a
 * block and in a translatable field, and a record saved again keeps its bytes and its change
 * date); a record-level field's text becomes a number in DataManager::storageValues() (as since
 * 0.78), "" no value there. Anything else passes through for the rules ("abc" stays "abc").
 *
 * Output: numeric text is a number (an int when whole and in range, else a float: "3.50" is 3.5),
 * empty ("") is null.
 *
 * @since 0.93.0
 */
final class NumberValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return $stored === '' ? null : self::number($stored);
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'number';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('number');
    }

    /** Numeric text as a number (an int when whole and in range, else a float); anything else as it is. */
    public static function number(mixed $value): mixed
    {
        if (!is_string($value) || !is_numeric($value)) {
            return $value;
        }
        $text = trim($value);
        // Whole numbers of up to 18 digits fit an int on every 64-bit PHP; longer ones are floats.
        if (preg_match('/^[+-]?\d+$/', $text) === 1 && strlen(ltrim(ltrim($text, '+-'), '0')) <= 18) {
            return (int) $text;
        }
        $float = (float) $text;

        return is_finite($float) ? $float : $value;
    }
}
