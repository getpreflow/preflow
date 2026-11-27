<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;

/**
 * A media field's value ("asset"): the asset's id (shape "id"), or with "asset.multiple" the ids
 * as a list (shape "id_list"). Stored and read out as a relation's (RelationValue::ids()): an
 * empty list is null, as AssetFieldType::toStorage() stores it, a list stored as JSON text (SQL)
 * decoded, the output only ever strings (RelationValue::outputIds()). references() names "asset" and its ids; URLs and sizes are the media package's.
 *
 * @since 0.93.0
 */
final class AssetValue implements FieldValue, DescribesShapes, ListsReferences
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return RelationValue::ids($input, self::multiple($field->config));
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return RelationValue::outputIds($stored, self::multiple($field->config));
    }

    public function shape(TypeFieldDefinition $field): string
    {
        return self::multiple($field->config) ? 'id_list' : 'id';
    }

    public function shapes(): array
    {
        return CoreValues::shapes('id', 'id_list');
    }

    public function references(mixed $stored, TypeFieldDefinition $field): array
    {
        $ids = RelationValue::idList($this->toOutput($stored, $field));

        return $ids === [] ? [] : ['asset' => $ids];
    }

    /**
     * Whether a media field's config holds a list of ids ("asset.multiple").
     *
     * @param array<string, mixed> $config
     */
    public static function multiple(array $config): bool
    {
        $asset = $config['asset'] ?? null;

        return is_array($asset) && (bool) ($asset['multiple'] ?? false);
    }
}
