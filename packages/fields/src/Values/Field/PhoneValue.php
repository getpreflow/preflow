<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\PhoneOptions;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Phone;

/**
 * A "phone" field's value, shape "phone": the stored object {as_typed, e164}.
 *
 * toStored(): the object, JSON text of it (both as stored, unchanged), or the number as plain
 * text, trimmed and read as typed, with its international form when it starts with + or 00 or,
 * a national number, in the field's "default_region" (as Folio's form reads it, since 0.93.0); no
 * value is null; an object without a number is passed on for the rule. toOutput(): the object, null when no number is stored.
 *
 * @since 0.93.0
 */
final class PhoneValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }

        if (is_string($input) && !str_starts_with(ltrim($input), '{')) {
            return Phone::of($input, PhoneOptions::fromConfig($field->config)->defaultRegion)->toStored();
        }

        return Phone::fromStored($input)?->toStored() ?? Link::decode($input) ?? $input;
    }

    /** @return array{as_typed: string, e164: string|null}|null */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return Phone::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'phone';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('phone');
    }
}
