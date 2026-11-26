<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a json field: JSON as text, stored as the editor wrote it (trimmed), so its layout
 * and key order stay. decode() reads it; it is null for empty or invalid text (reading never
 * fails; the field's check refuses invalid text on save).
 *
 * @since 0.91.0
 */
final readonly class JsonText
{
    /** The text a stored value holds, null when empty. A site that stored an array (a seed) gets its JSON. */
    public static function fromStored(mixed $stored): ?string
    {
        if (is_array($stored)) {
            try {
                return $stored === [] ? null : json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null; // text that is no UTF-8: no JSON to show
            }
        }
        if (!is_string($stored) || trim($stored) === '') {
            return null;
        }

        return trim($stored);
    }

    /** The decoded value (array, text, number, bool); null for empty or invalid text, and for the JSON text "null". */
    public static function decode(mixed $stored): mixed
    {
        $text = self::fromStored($stored);

        return $text !== null && json_validate($text) ? json_decode($text, true) : null;
    }
}
