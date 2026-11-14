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

    /** The operators where() and orWhere() take (any case; stored upper-case). */
    public const OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN'];

    /** A field name: letters, digits and underscores, not starting with a digit. */
    private const FIELD = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * A condition AND'd to the chain: where($field, $value) compares with "=", or
     * where($field, $operator, $value) with one of OPERATORS. A null value compares with NULL
     * ("=": IS NULL, "!=" / "<>": IS NOT NULL); IN and NOT IN take a list.
     *
     * @throws \InvalidArgumentException for a field name that is no identifier or an operator
     *         that is not in OPERATORS
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        return $this->addWhere($field, $operator, $value, func_num_args() === 2, 'AND');
    }

    /** As where(), OR'd to the chain. */
    public function orWhere(string $field, mixed $operator, mixed $value = null): self
    {
        return $this->addWhere($field, $operator, $value, func_num_args() === 2, 'OR');
    }

    private function addWhere(string $field, mixed $operator, mixed $value, bool $twoArguments, string $boolean): self
    {
        self::assertField($field);
        if ($twoArguments) {
            $value = $operator;
            $operator = '=';
        } else {
            $operator = self::operator($operator);
            if (in_array($operator, ['IN', 'NOT IN'], true) && !is_array($value)) {
                throw new \InvalidArgumentException(sprintf('Operator %s needs a list value.', $operator));
            }
        }

        $this->wheres[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $value,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /** The operator upper-case with single spaces, or an exception when it is not in OPERATORS. */
    private static function operator(mixed $operator): string
    {
        $normalized = is_string($operator) ? strtoupper((string) preg_replace('/\s+/', ' ', trim($operator))) : null;
        if ($normalized === null || !in_array($normalized, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator %s.', self::shown($operator)));
        }

        return $normalized;
    }

    /**
     * @throws \InvalidArgumentException for a field name that is no identifier
     */
    private static function assertField(string $field): void
    {
        if (preg_match(self::FIELD, $field) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid field name %s: a field name has letters, digits and underscores and does not start with a digit.', self::shown($field)));
        }
    }

    /** A value for an error message: quoted, escaped, at most 64 characters of it. */
    private static function shown(mixed $value): string
    {
        if (!is_string($value)) {
            return get_debug_type($value);
        }
        $cut = mb_strlen($value) > 64 ? mb_substr($value, 0, 64) . '…' : $value;

        return '"' . addcslashes($cut, "\0..\37\"\\\177") . '"';
    }

    /** @var list<list<array{field: string, operator: string, value: mixed}>> */
    private array $anyGroups = [];

    private const ANY_OPERATORS = ['=', 'CONTAINS', 'IN', 'HAS'];

    /**
     * One group of conditions of which at least one must hold (OR). Every group
     * is AND'd with the other wheres and groups.
     *
     * Operators: "=" (a null value matches a missing or NULL field), "CONTAINS" (case-insensitive substring), "IN" (value is a
     * list; empty matches nothing) and "HAS" (the column holds a JSON array of
     * ids or one id; true if any given id is in it; empty list matches nothing).
     *
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     */
    public function whereAny(array $conditions): self
    {
        $group = [];
        foreach ($conditions as $condition) {
            $operator = is_string($condition['operator'] ?? null) ? strtoupper($condition['operator']) : '';
            if (!in_array($operator, self::ANY_OPERATORS, true)) {
                throw new \InvalidArgumentException(sprintf('Unsupported operator %s in whereAny().', self::shown($condition['operator'] ?? '')));
            }
            if (in_array($operator, ['IN', 'HAS'], true) && !is_array($condition['value'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Operator %s in whereAny() needs a list value.', $operator));
            }
            $field = is_string($condition['field'] ?? null) ? $condition['field'] : '';
            self::assertField($field);
            $group[] = ['field' => $field, 'operator' => $operator, 'value' => $condition['value'] ?? null];
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
        self::assertField($field);
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
        foreach ($fields as $field) {
            self::assertField((string) $field);
        }
        $this->searchTerm = $term;
        $this->searchFields = $fields;
        return $this;
    }

    /** @return array<int, array{field: string, operator: string, value: mixed, boolean: string}> */
    /** @var array{fields: array<string, true>, locale: string, default: string, available: list<string>}|null */
    private ?array $localization = null;

    /**
     * Compare these (translatable) fields by their value in one locale. A
     * stored plain value counts as the default locale's value. $available are
     * the type's locales: a stored map that holds only other locales than the
     * two compared (e.g. only "de" when comparing "en") is still read as a map,
     * so its value in the locale is null, as on SQL storage.
     *
     * @param list<string> $fields
     * @param list<string> $available
     *
     * @since 0.76 the $available parameter
     */
    public function localize(array $fields, string $locale, string $default, array $available = []): void
    {
        $this->localization = [
            'fields' => array_fill_keys($fields, true),
            'locale' => $locale,
            'default' => $default,
            'available' => array_values(array_unique([$default, $locale, ...array_map('strval', $available)])),
        ];
    }

    /** @return array{locale: string, default: string, available: list<string>}|null */
    public function localizationFor(string $field): ?array
    {
        if ($this->localization === null || !isset($this->localization['fields'][$field])) {
            return null;
        }

        return ['locale' => $this->localization['locale'], 'default' => $this->localization['default'], 'available' => $this->localization['available']];
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
