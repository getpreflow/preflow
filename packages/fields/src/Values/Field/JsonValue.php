<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\JsonText;

/**
 * A "json" field's value, shape "json": stored as JSON text (as the editor wrote it, trimmed),
 * output decoded. toStored(): text is trimmed; a list or object (an API body's JSON) is written as
 * JSON text; no value is null; anything else (a number, true/false) passes through for the rule,
 * which wants text. toOutput(): the decoded value; null when empty or not valid JSON.
 *
 * @since 0.93.0
 */
final class JsonValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }
        if (is_string($input) || is_array($input)) {
            return JsonText::fromStored($input) ?? $input;
        }

        return $input;
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return JsonText::decode($stored);
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
