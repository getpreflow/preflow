<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

/**
 * What the twelve field values share (internal): which inputs hold no value at all, and the
 * shape descriptions of this package's output shapes.
 *
 * @internal
 * @since 0.93.0
 */
final class StoredShapes
{
    /** The output shapes this package adds to the core's, each with what it is (frozen in version 1). */
    public const SHAPES = [
        'json' => 'JSON, decoded: an object, a list, text, a number or true/false; null when nothing valid is stored.',
        'link' => 'An object {kind, target, label, new_tab}: kind is "record", "url", "email", "phone" or "asset"; target is {type, id} for a record, else text; label is text, a text per language or null. Or null.',
        'phone' => 'An object {as_typed, e164}: the number as typed, and its international form (null when the country is not known). Or null.',
        'color' => 'A colour as "#rrggbb", or "#rrggbbaa" with transparency, in lower case; or null.',
        'datetime' => 'A moment in UTC as "Y-m-d\TH:i:s\Z", or null.',
        'time' => 'A time of day as "HH:MM", or null.',
        'daterange' => 'An object {start, end}: both "Y-m-d" dates, or both UTC moments "Y-m-d\TH:i:s\Z". Or null.',
        'embed' => 'An object {provider, id, url, poster}: a video of a known host, poster is an image id or null. Or null.',
        'table' => 'An object {head, rows}: head is a list of cells or null, rows a list of lists of cells; a cell is text or a text per language. Or null.',
        'location' => 'An object {lat, lng, address}: latitude and longitude in degrees, address text or null. Or null.',
    ];

    /**
     * The entries of the core's shapes and of SHAPES for $names.
     *
     * @return array<string, string>
     */
    public static function shapes(string ...$names): array
    {
        return array_intersect_key(\Preflow\Data\Values\CoreValues::SHAPES + self::SHAPES, array_flip($names));
    }

    /** Whether an input holds no value: null, empty or blank text, an empty list. */
    public static function blank(mixed $input): bool
    {
        return $input === null || $input === [] || (is_string($input) && trim($input) === '');
    }
}
