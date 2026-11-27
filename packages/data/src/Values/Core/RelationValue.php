<?php

declare(strict_types=1);

namespace Preflow\Data\Values\Core;

use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;

/**
 * A "relation" field's value: the related record's id (shape "id"), or with "relation.multiple"
 * the ids as a list (shape "id_list"; FieldKinds holds such a field as a list, listWhen()).
 *
 * Stored: a single id as text (a number id as its text); a multiple field's ids as a list, also
 * when given as JSON text or a comma string, without empty entries. An empty list is null (no
 * value), so "required" refuses it. Output: the same, a list stored as JSON text (SQL) decoded.
 * references() names the target type ("relation.to") and its ids.
 *
 * @since 0.93.0
 */
final class RelationValue implements FieldValue, DescribesShapes, ListsReferences
{
    public function toStored(mixed $input, TypeFieldDefinition $field): mixed
    {
        return self::ids($input, self::multiple($field->config));
    }

    public function toOutput(mixed $stored, TypeFieldDefinition $field): mixed
    {
        return self::ids($stored, self::multiple($field->config));
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
        $relation = $field->config['relation'] ?? null;
        $target = is_array($relation) ? ($relation['to'] ?? null) : null;
        if (!is_string($target) || $target === '') {
            return [];
        }
        $ids = self::idList($this->toOutput($stored, $field));

        return $ids === [] ? [] : [$target => $ids];
    }

    /**
     * Whether a relation field's config holds a list of ids ("relation.multiple").
     *
     * @param array<string, mixed> $config
     */
    public static function multiple(array $config): bool
    {
        return is_array($config['relation'] ?? null) && (bool) ($config['relation']['multiple'] ?? false);
    }

    /**
     * One id, or with $multiple a list of ids, as stored and read out (also for a media field,
     * AssetValue): a number id as its text, a list given as JSON text or a comma string as a list,
     * without empty entries, an empty list null. A value of another kind passes through.
     *
     * @internal
     */
    public static function ids(mixed $value, bool $multiple): mixed
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if ($value === [] || ($multiple && $value === '')) {
            return null;
        }
        if (!$multiple) {
            return $value;
        }
        if (is_string($value)) {
            $text = trim($value);
            $decoded = str_starts_with($text, '[') ? json_decode($text, true) : null;
            $value = is_array($decoded) && array_is_list($decoded) ? $decoded : explode(',', $text);
        }
        if (!is_array($value)) {
            return $value;
        }
        $ids = [];
        foreach ($value as $id) {
            $id = is_int($id) ? (string) $id : (is_string($id) ? trim($id) : $id);
            if ($id !== '' && $id !== null) {
                $ids[] = $id;
            }
        }

        return $ids === [] ? null : $ids;
    }

    /**
     * The ids an output value holds, as a list of strings.
     *
     * @return list<string>
     * @internal
     */
    public static function idList(mixed $output): array
    {
        $ids = is_array($output) ? $output : [$output];

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== ''));
    }
}
