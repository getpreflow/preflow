<?php

declare(strict_types=1);

namespace Preflow\Fields\Rules;

use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Location;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * A location field's value (Location's stored object, or JSON text of it) has a latitude from -90
 * to 90 and a longitude from -180 to 180, both numbers (or numbers as text); the address is text
 * or null. A refusal names the coordinate that is out of bounds. A place needs both coordinates:
 * an address alone is refused. An empty value (nothing filled in) passes ("required" decides).
 *
 * Registered for every "location" field (FieldKinds::rules()).
 *
 * @since 0.91.0
 */
final class ValidLocation implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        $data = Link::decode($value);
        if ($data === null) {
            return new Message('validation.location');
        }
        $filled = static fn (mixed $v): bool => $v !== null && !(is_string($v) && trim($v) === '');
        if (!$filled($data['lat'] ?? null) && !$filled($data['lng'] ?? null) && !$filled($data['address'] ?? null)) {
            return true;
        }
        $lat = Location::number($data['lat'] ?? null);
        $lng = Location::number($data['lng'] ?? null);
        $address = $data['address'] ?? null;
        if ($lat === null || $lng === null || ($address !== null && !is_string($address))) {
            return new Message('validation.location');
        }
        if (!Location::isLatitude($lat)) {
            return new Message('validation.location_lat');
        }

        return Location::isLongitude($lng) ? true : new Message('validation.location_lng');
    }
}
