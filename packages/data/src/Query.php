<?php

declare(strict_types=1);

namespace Preflow\Data;

final class Query
{
    /** @var array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    private array $wheres = [];

    /** @var array<int, array{field: string, direction: SortDirection, numeric: bool}> */
    private array $orderBy = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private ?string $searchTerm = null;
    /** @var string[] */
    private array $searchFields = [];

    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'AND',
        ];

        return $this;
    }

    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => 'OR',
        ];

        return $this;
    }

    /** @var list<list<array{field: string, operator: string, value: mixed}>> */
    private array $anyGroups = [];

    private const ANY_OPERATORS = ['=', 'CONTAINS', 'IN', 'HAS'];

    /**
     * One group of conditions of which at least one must hold (OR). Every group
     * is AND'd with the other wheres and groups.
     *
     * Operators: "=", "CONTAINS" (case-insensitive substring), "IN" (value is a
     * list; empty matches nothing) and "HAS" (the column holds a JSON array of
     * ids or one id; true if any given id is in it; empty list matches nothing).
     *
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     */
    public function whereAny(array $conditions): self
    {
        $group = [];
        foreach ($conditions as $condition) {
            $operator = strtoupper((string) ($condition['operator'] ?? ''));
            if (!in_array($operator, self::ANY_OPERATORS, true)) {
                throw new \InvalidArgumentException(sprintf('Unsupported operator "%s" in whereAny().', $condition['operator'] ?? ''));
            }
            if (in_array($operator, ['IN', 'HAS'], true) && !is_array($condition['value'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Operator %s in whereAny() needs a list value.', $operator));
            }
            $group[] = ['field' => (string) $condition['field'], 'operator' => $operator, 'value' => $condition['value'] ?? null];
        }
        if ($group !== []) {
            $this->anyGroups[] = $group;
        }

        return $this;
    }

    /** @return list<list<array{field: string, operator: string, value: mixed}>> */
    public function getAnyGroups(): array { return $this->anyGroups; }

    /**
     * @param bool $numeric compare as numbers even where the column stores text
     *                      (SQL TEXT columns otherwise sort "10" before "2")
     */
    public function orderBy(string $field, SortDirection $direction = SortDirection::Asc, bool $numeric = false): self
    {
        $this->orderBy[] = [
            'field' => $field,
            'direction' => $direction,
            'numeric' => $numeric,
        ];

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function search(string $term, array $fields = []): self
    {
        $this->searchTerm = $term;
        $this->searchFields = $fields;
        return $this;
    }

    /** @return array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    /** @var array{fields: array<string, true>, locale: string, default: string}|null */
    private ?array $localization = null;

    /**
     * Compare these (translatable) fields by their value in one locale. A
     * stored plain value counts as the default locale's value.
     *
     * @param list<string> $fields
     */
    public function localize(array $fields, string $locale, string $default): void
    {
        $this->localization = ['fields' => array_fill_keys($fields, true), 'locale' => $locale, 'default' => $default];
    }

    /** @return array{locale: string, default: string}|null */
    public function localizationFor(string $field): ?array
    {
        if ($this->localization === null || !isset($this->localization['fields'][$field])) {
            return null;
        }

        return ['locale' => $this->localization['locale'], 'default' => $this->localization['default']];
    }

    public function getWheres(): array { return $this->wheres; }

    /** @return array<int, array{field: string, direction: SortDirection}> */
    public function getOrderBy(): array { return $this->orderBy; }

    public function getLimit(): ?int { return $this->limit; }
    public function getOffset(): ?int { return $this->offset; }
    public function getSearchTerm(): ?string { return $this->searchTerm; }
    /** @return string[] */
    public function getSearchFields(): array { return $this->searchFields; }
}
