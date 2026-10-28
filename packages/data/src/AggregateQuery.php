<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Groups the records a QueryBuilder's filters select and computes results per group: counts,
 * sums, averages, minima and maxima, filtered after grouping (having), ordered and limited.
 * Without groupBy() it gives one row of totals.
 *
 * Every name is checked against the type's fields (or the model's), so no caller text reaches
 * the storage as SQL. The rules are the same on every storage:
 * - count() counts records; every other measure skips missing values: NULL, and '' in number
 *   fields (sum, avg and the numeric min/max skip '' in any field, as it is no number).
 * - sum() and avg() treat values as numbers; min(), max() and countDistinct() do for number
 *   fields and compare stored values as they are for any other field.
 * - avg/min/max of no values are null, sum of no values is 0; numbers come back as int when
 *   integral, else as float (also number fields among the group values).
 * - Ordered by orderBy() first, then by the group fields ascending (also as tie-breakers).
 *
 * The builder's own orderBy(), limit() and offset() do not apply; this query has its own.
 */
final class AggregateQuery
{
    private const OPERATORS = ['=', '!=', '<', '<=', '>', '>='];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<array{fn: string, field: ?string, as: string}> */
    private array $measures = [];

    /** @var list<array{alias: string, operator: string, value: int|float}> */
    private array $havings = [];

    /** @var list<array{name: string, direction: SortDirection}> */
    private array $orders = [];

    private ?int $limit = null;
    private ?int $offset = null;

    /**
     * Made by QueryBuilder::aggregate().
     *
     * @param list<string> $fields the fields the type or model has (names allowed in a query)
     * @param list<string> $numericFields the fields stored as numbers
     * @param list<string> $multipleFields the relation fields holding a list of ids
     */
    public function __construct(
        private readonly StorageDriver $driver,
        private readonly string $table,
        private readonly Query $query,
        private readonly array $fields,
        private readonly array $numericFields = [],
        private readonly array $multipleFields = [],
    ) {}

    /** Group by these fields (a single relation groups by the related id; a multiple relation is refused). */
    public function groupBy(string ...$fields): self
    {
        foreach ($fields as $field) {
            $this->field($field);
            if (in_array($field, $this->multipleFields, true)) {
                throw new \InvalidArgumentException(sprintf('Cannot group by "%s": it holds a list of ids.', $field));
            }
            if (!in_array($field, $this->groups, true)) {
                $this->groups[] = $field;
            }
        }

        return $this;
    }

    /** The number of records. */
    public function count(string $as = 'count'): self
    {
        return $this->measure('count', null, $as);
    }

    /** The number of different values of a field. */
    public function countDistinct(string $field, string $as): self
    {
        return $this->measure('countDistinct', $field, $as);
    }

    public function sum(string $field, string $as): self
    {
        return $this->measure('sum', $field, $as);
    }

    public function avg(string $field, string $as): self
    {
        return $this->measure('avg', $field, $as);
    }

    public function min(string $field, string $as): self
    {
        return $this->measure('min', $field, $as);
    }

    public function max(string $field, string $as): self
    {
        return $this->measure('max', $field, $as);
    }

    /**
     * Keep only the groups whose result (a measure's alias) compares to the value. A missing
     * result (null) matches no comparison.
     */
    public function having(string $alias, string $operator, int|float $value): self
    {
        $measure = null;
        foreach ($this->measures as $candidate) {
            if ($candidate['as'] === $alias) {
                $measure = $candidate;
            }
        }
        if ($measure === null) {
            throw new \InvalidArgumentException(sprintf('having() names "%s", which is no result of this query.', $alias));
        }
        if (in_array($measure['fn'], ['min', 'max'], true) && !$this->isNumeric((string) $measure['field'])) {
            throw new \InvalidArgumentException(sprintf('having() compares numbers; "%s" is the %s of a field that is no number.', $alias, $measure['fn']));
        }
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s" in having(); use one of %s.', $operator, implode(' ', self::OPERATORS)));
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('having() needs a finite number.');
        }
        $this->havings[] = ['alias' => $alias, 'operator' => $operator, 'value' => $value];

        return $this;
    }

    /** Order by a result's alias or a group field (checked when the query runs). */
    public function orderBy(string $aliasOrGroupField, SortDirection $direction = SortDirection::Asc): self
    {
        $this->orders[] = ['name' => $aliasOrGroupField, 'direction' => $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit() needs a number of rows of at least 0.');
        }
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('offset() needs a number of rows of at least 0.');
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * One row per group: the group fields under their names, then the results under their
     * aliases.
     *
     * @return list<array<string, mixed>>
     * @throws \LogicException when the storage driver cannot aggregate
     */
    public function get(): array
    {
        $this->validate();
        if (!$this->driver instanceof AggregatingStorage) {
            throw new \LogicException(sprintf(
                'Storage driver %s cannot run aggregate queries: it does not implement %s.',
                $this->driver::class,
                AggregatingStorage::class,
            ));
        }

        return array_map($this->typed(...), array_values($this->driver->aggregate($this)));
    }

    /** @return list<string> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return list<array{fn: string, field: ?string, as: string}> fn: count, countDistinct, sum, avg, min or max */
    public function measures(): array
    {
        return $this->measures;
    }

    /** @return list<array{alias: string, operator: string, value: int|float}> */
    public function havings(): array
    {
        return $this->havings;
    }

    /**
     * The orders to apply: those given, then every group field not among them, ascending.
     *
     * @return list<array{name: string, direction: SortDirection}>
     */
    public function orders(): array
    {
        $orders = $this->orders;
        $named = array_column($orders, 'name');
        foreach ($this->groups as $field) {
            if (!in_array($field, $named, true)) {
                $orders[] = ['name' => $field, 'direction' => SortDirection::Asc];
            }
        }

        return $orders;
    }

    public function limitValue(): ?int
    {
        return $this->limit;
    }

    public function offsetValue(): ?int
    {
        return $this->offset;
    }

    /** The filter (wheres, search, locale) records must pass before they are grouped. */
    public function query(): Query
    {
        return $this->query;
    }

    /** @return list<string> */
    public function numericFields(): array
    {
        return $this->numericFields;
    }

    public function table(): string
    {
        return $this->table;
    }

    private function measure(string $fn, ?string $field, string $as): self
    {
        if ($field !== null) {
            $this->field($field);
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid result name "%s": use letters, digits and _.', $as));
        }
        if (in_array($as, array_column($this->measures, 'as'), true)) {
            throw new \InvalidArgumentException(sprintf('The result name "%s" is used twice.', $as));
        }
        $this->measures[] = ['fn' => $fn, 'field' => $field, 'as' => $as];

        return $this;
    }

    private function field(string $field): void
    {
        if (!in_array($field, $this->fields, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown field "%s" in an aggregate of %s.', $field, $this->table));
        }
    }

    private function isNumeric(string $field): bool
    {
        return in_array($field, $this->numericFields, true);
    }

    /** What can only be checked once the whole query is known. */
    private function validate(): void
    {
        if ($this->groups === [] && $this->measures === []) {
            throw new \InvalidArgumentException('An aggregate query needs a groupBy() or a result (count(), sum(), …).');
        }
        $aliases = array_column($this->measures, 'as');
        foreach ($aliases as $alias) {
            if (in_array($alias, $this->groups, true)) {
                throw new \InvalidArgumentException(sprintf('The result name "%s" is also a group field.', $alias));
            }
        }
        foreach ($this->orders as $order) {
            if (!in_array($order['name'], $aliases, true) && !in_array($order['name'], $this->groups, true)) {
                throw new \InvalidArgumentException(sprintf('orderBy() names "%s", which is neither a result nor a group field.', $order['name']));
            }
        }
    }

    /**
     * A row as the rules say: numbers as int or float, sum of nothing 0.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function typed(array $row): array
    {
        $typed = [];
        foreach ($this->groups as $field) {
            $value = $row[$field] ?? null;
            $typed[$field] = $this->isNumeric($field) ? self::number($value) : $value;
        }
        foreach ($this->measures as $measure) {
            $value = $row[$measure['as']] ?? null;
            $typed[$measure['as']] = match ($measure['fn']) {
                'count', 'countDistinct' => (int) $value,
                'sum' => self::number($value) ?? 0,
                'avg' => self::number($value),
                default => $this->isNumeric((string) $measure['field']) ? self::number($value) : $value,
            };
        }

        return $typed;
    }

    /** An int when integral (and exact as one), else a float; null and '' are null; anything else as it is. */
    private static function number(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || !is_numeric($value)) {
            return $value;
        }
        $float = (float) $value;
        if (floor($float) === $float && abs($float) <= 2 ** 53) {
            return (int) $float;
        }

        return $float;
    }
}
