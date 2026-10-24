<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;

/**
 * Labels of related records for the admin list: relation cells for a page of records (one query
 * per target type, never one per row), filter chips and the relation filter's options. A label is
 * the relation's "labelField" when set and non-empty, else the record's label (RecordLabeler),
 * both in the target's default locale.
 */
final class RelationLabels
{
    /** The relation filter lists the target's records up to this many; above, it needs a search. */
    public const int OPTION_LIMIT = 200;

    public function __construct(
        private readonly DataManager $dm,
        private readonly RecordLabeler $labeler,
    ) {}

    /**
     * @param list<string>        $fields  relation fields of $def
     * @param list<DynamicRecord> $records the page
     * @return array<string, array<string, string>> field => id => label (ids that no longer resolve left out)
     */
    public function forRecords(TypeDefinition $def, array $fields, array $records): array
    {
        $wanted = []; // target => id => true
        foreach ($fields as $field) {
            $target = self::target($def, $field);
            foreach ($records as $record) {
                foreach (self::ids($record->localized($def->locales->default)->get($field)) as $id) {
                    $wanted[$target][$id] = true;
                }
            }
        }

        $found = []; // target => id => record
        foreach ($wanted as $target => $ids) {
            $found[$target] = $this->load($target, array_map('strval', array_keys($ids)));
        }

        $labels = [];
        foreach ($fields as $field) {
            $target = self::target($def, $field);
            $labelField = self::labelField($def, $field);
            $labels[$field] = [];
            foreach ($records as $record) {
                foreach (self::ids($record->localized($def->locales->default)->get($field)) as $id) {
                    if (isset($found[$target][$id])) {
                        $labels[$field][$id] = $this->label($found[$target][$id], $labelField);
                    }
                }
            }
        }

        return $labels;
    }

    /**
     * @param list<string> $ids
     * @return array<string, string> id => label, in the order asked for
     */
    public function labels(string $target, array $ids, ?string $labelField): array
    {
        $records = $this->load($target, $ids);
        $labels = [];
        foreach ($ids as $id) {
            if (isset($records[$id])) {
                $labels[$id] = $this->label($records[$id], $labelField);
            }
        }

        return $labels;
    }

    /**
     * The target's records as id => label, or null when it has more than $limit (or is unknown).
     *
     * @return array<string, string>|null
     */
    public function options(string $target, ?string $labelField, int $limit = self::OPTION_LIMIT): ?array
    {
        $registry = $this->dm->getTypeRegistry();
        if ($registry === null || !$registry->has($target)) {
            return null;
        }
        $result = $this->dm->queryType($target)->limit($limit + 1)->get();
        if ($result->total() > $limit || count($result->items()) > $limit) {
            return null;
        }

        $options = [];
        foreach ($result->items() as $record) {
            $options[(string) $record->getId()] = $this->label($record, $labelField);
        }

        return $options;
    }

    /**
     * The ids a stored relation value holds (single: id string, multiple: JSON array).
     *
     * @return list<string>
     */
    public static function ids(mixed $stored): array
    {
        if (is_string($stored) && str_starts_with($stored, '[')) {
            $stored = json_decode($stored, true);
        }
        $ids = [];
        foreach ((array) $stored as $id) {
            if ((is_string($id) || is_int($id)) && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /** The relation's "labelField", if set. */
    public static function labelField(TypeDefinition $def, string $field): ?string
    {
        $labelField = $def->fields[$field]->config['relation']['labelField'] ?? null;

        return is_string($labelField) && $labelField !== '' ? $labelField : null;
    }

    private static function target(TypeDefinition $def, string $field): string
    {
        return (string) ($def->fields[$field]->config['relation']['to'] ?? '');
    }

    /**
     * @param list<string> $ids
     * @return array<string, DynamicRecord> id => record
     */
    private function load(string $target, array $ids): array
    {
        $registry = $this->dm->getTypeRegistry();
        if ($ids === [] || $registry === null || !$registry->has($target)) {
            return [];
        }
        $idField = $registry->get($target)->idField;
        $records = [];
        foreach ($this->dm->queryType($target)->whereAny([['field' => $idField, 'operator' => 'IN', 'value' => $ids]])->get()->items() as $record) {
            $records[(string) $record->getId()] = $record;
        }

        return $records;
    }

    private function label(DynamicRecord $record, ?string $labelField): string
    {
        if ($labelField !== null) {
            $value = $record->localized($record->getType()->locales->default)->get($labelField);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->labeler->label($record);
    }
}
