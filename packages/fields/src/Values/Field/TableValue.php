<?php

declare(strict_types=1);

namespace Preflow\Fields\Values\Field;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Fields\Values\Link;
use Preflow\Fields\Values\Table;

/**
 * A "table" field's value, shape "table": the object {head, rows}, every row as wide as the table.
 * toStored(): the object or JSON text of it, padded; no value (or only empty cells) is null; an
 * object that is no table is passed on for the rule. toOutput(): the object, null when none is stored.
 *
 * @since 0.93.0
 */
final class TableValue implements FieldValue, DescribesShapes
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        if (StoredShapes::blank($input)) {
            return null;
        }
        $table = Table::fromStored($input);
        if ($table !== null) {
            return $table->toStored();
        }

        return Link::decode($input) ?? $input;
    }

    /** @return array{head: list<string|array<string, string>>|null, rows: list<list<string|array<string, string>>>}|null */
    public function toOutput(mixed $stored, TypeFieldDefinition $field): ?array
    {
        return Table::fromStored($stored)?->toStored();
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return 'table';
    }

    public function shapes(): array
    {
        return StoredShapes::shapes('table');
    }
}
