<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Location;

/**
 * A "location" field's value, shape "location": the object {lat, lng, address}. toStored(): the
 * object or JSON text of it (a coordinate may be a number or numeric text); no value is null; an
 * object that is no place (a coordinate missing or out of bounds) is passed on for the rule.
 * toOutput(): the object, null when none is stored.
 *
 * @since 0.93.0
 */
final class LocationValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        return Location::fromStored($input)?->toStored() ?? Link::decode($input) ?? $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return Location::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'location';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('location');
    }
}
