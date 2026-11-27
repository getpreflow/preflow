<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;

/**
 * A "json" field's value, shape "json": stored as JSON text (as the editor wrote it, trimmed),
 * output decoded. toStored(): text is trimmed; a list or object (an API body's JSON, an empty one
 * too) is written as JSON text; only null and blank text are no value; anything else (a number,
 * true/false) passes through for the rule, which wants text. toOutput(): the decoded value; null
 * when empty or not valid JSON; an empty object stays an object (stdClass), so {} and [] differ.
 *
 * @since 0.93.0
 */
final class JsonValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if ($input === null || (is_string($input) && trim($input) === '')) {
            return null;
        }
        if (is_string($input)) {
            return trim($input);
        }
        if ($input instanceof \stdClass || is_array($input)) {
            try {
                return json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $input; // no UTF-8 or no JSON: the rule refuses it
            }
        }

        return $input;
    }

    /** The decoded value; an empty object stays an object (stdClass, which encodes as {}), so {} and [] stay apart. */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        if (is_array($stored)) {
            return $stored;
        }
        if (!is_string($stored) || trim($stored) === '' || !json_validate($stored)) {
            return null;
        }

        return self::plain(json_decode($stored, false));
    }

    private static function plain(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::plain(...), $value);
        }
        if ($value instanceof \stdClass) {
            $vars = get_object_vars($value);

            return $vars === [] ? $value : array_map(self::plain(...), $vars);
        }

        return $value;
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'json';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('json');
    }
}
