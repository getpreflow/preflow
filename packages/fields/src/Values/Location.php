<?php

declare(strict_types=1);

namespace Preflow\Fields\Values;

/**
 * The value of a location field: a place as latitude and longitude (degrees, WGS 84 as maps and
 * phones give them: north and east positive) and an optional address line for people.
 *
 * Stored as an object with the keys lat, lng and address (toStored()):
 *
 *     {"lat": 47.3769, "lng": 8.5417, "address": "Bahnhofplatz, 8001 Zürich"}
 *
 * A JSON file holds the object, SQL storage JSON text of it; fromStored() reads both. The address
 * is the same in every language (a field is not translatable); no map is drawn by default (a site
 * plugs one into the editor with a LocationPicker, and draws its own on the page).
 *
 * @since 0.91.0
 */
final readonly class Location
{
    public function __construct(
        public float $lat,
        public float $lng,
        public ?string $address = null,
    ) {
        if (!self::isLatitude($lat) || !self::isLongitude($lng)) {
            throw new \InvalidArgumentException("A location needs a latitude from -90 to 90 and a longitude from -180 to 180, got {$lat}, {$lng}.");
        }
    }

    /**
     * The place a stored value holds (the object, or JSON text of it; a coordinate may be a number
     * or a number as text). Null for an empty value and for one that is no place (a coordinate
     * missing or out of bounds); reading never fails.
     */
    public static function fromStored(mixed $stored): ?self
    {
        $data = Link::decode($stored);
        if ($data === null) {
            return null;
        }
        $lat = self::number($data['lat'] ?? null);
        $lng = self::number($data['lng'] ?? null);
        if ($lat === null || $lng === null || !self::isLatitude($lat) || !self::isLongitude($lng)) {
            return null;
        }
        $address = is_string($data['address'] ?? null) && trim($data['address']) !== '' ? trim($data['address']) : null;

        return new self($lat, $lng, $address);
    }

    /** @return array{lat: float, lng: float, address: ?string} */
    public function toStored(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng, 'address' => $this->address];
    }

    /** A `geo:` address of the place (RFC 5870), which phones open in their map app. */
    public function geoHref(): string
    {
        return 'geo:' . self::coordinate($this->lat) . ',' . self::coordinate($this->lng);
    }

    /** A coordinate as a number: a number, or a number as text ("47.3769", "-8.5"); null for anything else. */
    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (is_string($value) && preg_match('/^\s*[+-]?(?:\d+\.?\d*|\.\d+)\s*$/', $value) === 1) {
            return (float) trim($value);
        }

        return null;
    }

    public static function isLatitude(float $lat): bool
    {
        return $lat >= -90.0 && $lat <= 90.0;
    }

    public static function isLongitude(float $lng): bool
    {
        return $lng >= -180.0 && $lng <= 180.0;
    }

    /** A coordinate as people write it (in an address, an input, a list): up to 7 decimals, no exponent, no trailing zeros. */
    public static function coordinate(float $value): string
    {
        $text = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
