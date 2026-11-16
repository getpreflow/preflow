<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\AggregateQuery;
use Preflow\Data\Query;

class QueryCompiler
{
    /** The table being compiled, for the column facts. */
    private ?string $table = null;

    /** @var array<string, array<string, array{bounded: bool, rule: bool}>> table => column => facts, as read once */
    private array $facts = [];

    /**
     * @param (\Closure(string): array<string, array{bounded: bool, rule: bool}>)|null $columns what the
     *     database says about a table's columns, read once per table (since 0.78): `bounded`, a
     *     column of bounded length (VARCHAR) its collation sorts with every level, so ORDER BY on
     *     a text field keeps it and its index (a long one, TEXT/LONGTEXT, goes through
     *     TextComparison::textOrder()); `rule`, a column whose collation is the text rule's (a
     *     field the query does not name as text, such as a list in a full-text index, is then
     *     compared through Dialect::exactText()). MysqlDriver gives it; without it every text
     *     field is sorted the safe way and every column compared by its own collation.
     */
    public function __construct(
        private readonly Dialect $dialect = new SqliteDialect(),
        private readonly ?\Closure $columns = null,
    ) {}

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compile(string $table, Query $query): array
    {
        $this->table = $table;
        $bindings = [];
        $sql = 'SELECT * FROM ' . $this->dialect->quoteIdentifier($table);

        $whereSql = $this->compileConditions($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $orderSql = $this->compileOrderBy($query);
        if ($orderSql !== '') {
            $sql .= ' ' . $orderSql;
        }

        if ($query->getLimit() !== null) {
            $sql .= ' LIMIT ' . $query->getLimit();
        }

        if ($query->getOffset() !== null) {
            $sql .= ' OFFSET ' . $query->getOffset();
        }

        return [$sql, $bindings];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileCount(string $table, Query $query): array
    {
        $this->table = $table;
        $bindings = [];
        $sql = 'SELECT COUNT(*) as total FROM ' . $this->dialect->quoteIdentifier($table);

        $whereSql = $this->compileConditions($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return [$sql, $bindings];
    }

    /**
     * SELECT of the group fields and results, filtered by the query's conditions before
     * grouping and by its havings after. Every name was checked by AggregateQuery; the having
     * values are bound (compared as numbers, as bound text would not be in SQLite).
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    public function compileAggregate(AggregateQuery $aggregate): array
    {
        $this->table = $aggregate->table();
        $query = $aggregate->query();
        $numeric = array_fill_keys($aggregate->numericFields(), true);
        $boolean = array_fill_keys($aggregate->booleanFields(), true);
        $bindings = [];
        $select = [];

        $groups = [];
        foreach ($aggregate->groups() as $field) {
            $groups[$field] = $this->stored($field, $query, $numeric, $boolean);
            $select[] = $groups[$field] . ' AS ' . $this->dialect->quoteIdentifier($field);
        }
        $measures = [];
        foreach ($aggregate->measures() as $measure) {
            $measures[$measure['as']] = $this->measure($measure, $query, $numeric, $boolean);
            $select[] = $measures[$measure['as']] . ' AS ' . $this->dialect->quoteIdentifier($measure['as']);
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $this->dialect->quoteIdentifier($aggregate->table());
        $whereSql = $this->compileConditions($query, $bindings);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }
        if ($groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $groups);
        }

        // Expressions rather than aliases: MySQL resolves a name in HAVING to a column first.
        $havings = [];
        foreach ($aggregate->havings() as $having) {
            $havings[] = $measures[$having['alias']] . " {$having['operator']} " . $this->dialect->numericValue('?');
            $bindings[] = $having['value'];
        }
        if ($havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $havings);
        }

        $orders = [];
        foreach ($aggregate->orders() as $order) {
            $name = $order['name'];
            $orders[] = (isset($groups[$name])
                ? $this->ordered($name, $query, $groups[$name])
                : $this->orderedMeasure($aggregate, $name, $measures[$name])) . ' ' . $order['direction']->value;
        }
        if ($orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        $limit = $aggregate->limitValue();
        $offset = $aggregate->offsetValue();
        if ($limit !== null || $offset !== null) {
            // An OFFSET needs a LIMIT in SQLite and MySQL alike.
            $sql .= ' LIMIT ' . ($limit ?? PHP_INT_MAX);
        }
        if ($offset !== null) {
            $sql .= ' OFFSET ' . $offset;
        }

        return [$sql, $bindings];
    }

    /** A MIN or MAX of a text field sorts by the rule, as the field does; any other result as it is. */
    private function orderedMeasure(AggregateQuery $aggregate, string $alias, string $expression): string
    {
        foreach ($aggregate->measures() as $measure) {
            if ($measure['as'] === $alias && in_array($measure['fn'], ['min', 'max'], true) && !in_array($measure['field'], $aggregate->numericFields(), true)) {
                return $this->ordered((string) $measure['field'], $aggregate->query(), $expression);
            }
        }

        return $expression;
    }

    /**
     * One result's SQL. sum and avg take numbers (of number fields); countDistinct, min and max
     * do for number fields and compare stored values otherwise. A sum of no values is 0.
     *
     * @param array{fn: string, field: ?string, as: string} $measure
     * @param array<string, true> $numeric
     * @param array<string, true> $boolean
     */
    private function measure(array $measure, Query $query, array $numeric, array $boolean): string
    {
        if ($measure['fn'] === 'count') {
            return 'COUNT(*)';
        }
        $field = (string) $measure['field'];
        $number = $this->dialect->numericValue($this->column($field, $query));
        $value = isset($numeric[$field]) ? $number : $this->stored($field, $query, $numeric, $boolean);

        return match ($measure['fn']) {
            'countDistinct' => "COUNT(DISTINCT {$value})",
            'sum' => "COALESCE(SUM({$number}), 0)",
            'avg' => "AVG({$number})",
            'min' => "MIN({$value})",
            'max' => "MAX({$value})",
            default => throw new \InvalidArgumentException(sprintf('Unknown aggregate function "%s".', $measure['fn'])),
        };
    }

    /**
     * A field's value as grouped and compared as stored: numbers and booleans as their columns
     * hold them, a text field (Query::comparesAsText()) by the text rule (its column's collation;
     * a translatable one's locale value through the dialect, compared()), any other value exactly
     * (case and all).
     *
     * @param array<string, true> $numeric
     * @param array<string, true> $boolean
     */
    private function stored(string $field, Query $query, array $numeric, array $boolean): string
    {
        $column = $this->column($field, $query);
        if (isset($numeric[$field]) || isset($boolean[$field])) {
            return $column;
        }

        return $query->comparesAsText($field) ? $this->compared($field, $query) : $this->dialect->exactText($column);
    }

    /**
     * ORDER BY's expression for a field's value: a text field's by the rule through
     * TextComparison::textOrder(), unless it is a plain column of bounded length (the column
     * facts), which its collation orders (its index stays usable).
     */
    private function ordered(string $field, Query $query, string $expression): string
    {
        if (!$this->dialect instanceof TextComparison || !$query->comparesAsText($field)) {
            return $expression;
        }
        $plain = $query->localizationFor($field) === null && ($this->facts($field)['bounded'] ?? false);

        return $plain ? $expression : $this->dialect->textOrder($expression);
    }

    /**
     * A field's value as =, !=, IN, NOT IN, ranges and ORDER BY compare it: a translatable text
     * field's locale value (a JSON value, without the column's collation) by the text rule where
     * the dialect has it (TextComparison); anything else as the column is, left to its collation
     * (a text field's column carries the rule), so its indexes stay usable.
     */
    private function compared(string $field, Query $query): string
    {
        $column = $this->column($field, $query);
        if ($this->exactOnRuleColumn($field, $query)) {
            return $this->dialect->exactText($column); // a list in a full-text index: its column carries the rule
        }
        if ($this->dialect instanceof TextComparison && $query->comparesAsText($field) && $query->localizationFor($field) !== null) {
            return $this->dialect->textValue($column);
        }

        return $column;
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function compileWheres(Query $query, array &$bindings): string
    {
        $wheres = $query->getWheres();

        if ($wheres === []) {
            return '';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
            $clause = $this->compileWhere($where, $query, $bindings);

            if ($i === 0) {
                $parts[] = $clause;
            } else {
                $parts[] = $where['boolean'] . ' ' . $clause;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * One where() condition. The operator is one of Query::OPERATORS (checked when it was
     * added); a null value compares with NULL ("=": IS NULL, "!=" / "<>": IS NOT NULL, any other
     * operator matches nothing), IN / NOT IN take a list (an empty IN matches nothing, an empty
     * NOT IN everything).
     *
     * @param array{field: string, operator: string, value: mixed, boolean: string} $where
     * @param array<int, mixed> $bindings
     */
    private function compileWhere(array $where, Query $query, array &$bindings): string
    {
        $operator = $where['operator'];
        // LIKE keeps the plain value: the dialect matches it by the rule itself.
        $column = in_array(strtoupper((string) $operator), ['LIKE', 'NOT LIKE'], true)
            ? $this->column($where['field'], $query)
            : $this->compared($where['field'], $query);
        if (!in_array($operator, Query::OPERATORS, true)) {
            throw new \InvalidArgumentException('Unsupported operator in a where condition.');
        }
        $value = $where['value'];

        if ($operator === 'IN' || $operator === 'NOT IN') {
            $values = array_values((array) $value);
            if ($values === []) {
                return $operator === 'IN' ? '0 = 1' : '1 = 1';
            }
            array_push($bindings, ...$values);

            return "{$column} {$operator} (" . implode(', ', array_fill(0, count($values), '?')) . ')';
        }
        if ($value === null) {
            return match ($operator) {
                '=' => "{$column} IS NULL",
                '!=', '<>' => "{$column} IS NOT NULL",
                default => '0 = 1',
            };
        }
        $bindings[] = $value;
        // LIKE matches case-insensitively, as the dialect writes it.
        if ($operator === 'LIKE' || $operator === 'NOT LIKE') {
            return $this->like($column, (string) $value, $operator === 'NOT LIKE');
        }

        return "{$column} {$operator} ?";
    }

    /**
     * The where chain, search and OR-groups, AND'd. The where chain may contain
     * orWhere() ORs, so it is parenthesized whenever something is AND'd to it.
     *
     * @param array<int, mixed> $bindings
     */
    private function compileConditions(Query $query, array &$bindings): string
    {
        $whereSql = $this->compileWheres($query, $bindings);
        $others = [];
        $searchSql = $this->compileSearch($query, $bindings);
        if ($searchSql !== '') {
            $others[] = $searchSql;
        }
        $anySql = $this->compileAnyGroups($query, $bindings);
        if ($anySql !== '') {
            $others[] = $anySql;
        }
        if ($whereSql === '') {
            return implode(' AND ', $others);
        }
        if ($others === []) {
            return $whereSql;
        }

        return '(' . $whereSql . ') AND ' . implode(' AND ', $others);
    }

    /**
     * Each group is "(a OR b OR …)"; groups are AND'd.
     *
     * @param array<int, mixed> $bindings
     */
    private function compileAnyGroups(Query $query, array &$bindings): string
    {
        $groups = [];
        foreach ($query->getAnyGroups() as $group) {
            $parts = [];
            foreach ($group as $condition) {
                $parts[] = $this->compileAnyCondition($condition, $query, $bindings);
            }
            $groups[] = '(' . implode(' OR ', $parts) . ')';
        }

        return implode(' AND ', $groups);
    }

    /**
     * @param array{field: string, operator: string, value: mixed} $condition
     * @param array<int, mixed> $bindings
     */
    private function compileAnyCondition(array $condition, Query $query, array &$bindings): string
    {
        // = and IN compare by the rule; CONTAINS matches the plain value, HAS a list's members exactly.
        $column = in_array($condition['operator'], ['IN', '='], true)
            || ($condition['operator'] === 'HAS' && $this->exactOnRuleColumn($condition['field'], $query))
            ? $this->compared($condition['field'], $query)
            : $this->column($condition['field'], $query);
        $value = $condition['value'];

        switch ($condition['operator']) {
            case 'CONTAINS':
                $pattern = '%' . strtr((string) $value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                $bindings[] = $pattern;
                return $this->like($column, $pattern, false, '!');
            case 'IN':
                $ids = array_values((array) $value);
                if ($ids === []) {
                    return '0 = 1';
                }
                return $this->dialect->inList($column, $ids, $bindings);
            case 'HAS':
                // An empty id is no id: it must not match a column holding ''.
                $ids = array_values(array_filter(array_map('strval', (array) $value), fn (string $id) => $id !== ''));
                if ($ids === []) {
                    return '0 = 1';
                }
                return $this->dialect->jsonArrayHasAny($column, $ids, $bindings);
            default:
                if ($value === null) {
                    return "{$column} IS NULL"; // a null value matches missing columns, as on the JSON driver
                }
                $bindings[] = $value;
                return "{$column} = ?";
        }
    }

    /**
     * @param array<int, mixed> $bindings
     */
    private function compileSearch(Query $query, array &$bindings): string
    {
        $term = $query->getSearchTerm();
        $fields = $query->getSearchFields();

        if ($term === null || $fields === []) {
            return '';
        }

        $parts = [];
        foreach ($fields as $field) {
            $pattern = '%' . $term . '%';
            $parts[] = $this->like($this->column($field, $query), $pattern, false);
            $bindings[] = $pattern;
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    /**
     * A case-insensitive match of $column against one bound pattern: the dialect's own SQL for it
     * (PatternMatching, which sees the pattern), else its operator.
     */
    private function like(string $column, string $pattern, bool $not, ?string $escape = null): string
    {
        if ($this->dialect instanceof PatternMatching) {
            return $this->dialect->likeSql($column, $pattern, $not, $escape);
        }

        return $column . ($not ? ' NOT ' : ' ') . $this->dialect->likeOperator() . ' ?' . ($escape === null ? '' : " ESCAPE '{$escape}'");
    }

    private function compileOrderBy(Query $query): string
    {
        $orders = $query->getOrderBy();

        if ($orders === []) {
            return '';
        }

        $parts = [];
        foreach ($orders as $order) {
            $parts[] = (($order['numeric'] ?? false)
                ? $this->dialect->numericOrder($this->column($order['field'], $query))
                : $this->ordered($order['field'], $query, $this->compared($order['field'], $query))) . " {$order['direction']->value}";
        }

        return 'ORDER BY ' . implode(', ', $parts);
    }

    /** A quoted column, or its localized expression for translatable fields. */
    /**
     * Whether a field the query compares exactly sits in a column of the rule's collation (a list
     * or an exact field in a full-text index, MySQL): only a query that names its text fields
     * (Query::namesTextFields()) says the others are exact.
     */
    private function exactOnRuleColumn(string $field, Query $query): bool
    {
        return $query->namesTextFields() && !$query->comparesAsText($field) && ($this->facts($field)['rule'] ?? false);
    }

    /** Drops the column facts read for $table (null: for every table); the next compile reads them again. */
    public function forgetFacts(?string $table = null): void
    {
        if ($table === null) {
            $this->facts = [];
        } else {
            unset($this->facts[$table]);
        }
    }

    /** @return array{bounded?: bool, rule?: bool} what the column facts say about the field's column */
    private function facts(string $field): array
    {
        if ($this->columns === null || $this->table === null) {
            return [];
        }
        if (!isset($this->facts[$this->table])) {
            // A table that is not there (yet) is remembered as empty too: one read per table, not one per
            // compile. forgetFacts() drops it once the table is made or changed through the driver.
            $this->facts[$this->table] = ($this->columns)($this->table);
        }

        return $this->facts[$this->table][$field] ?? [];
    }

    private function column(string $field, Query $query): string
    {
        $quoted = $this->dialect->quoteIdentifier($field);
        $localization = $query->localizationFor($field);
        if ($localization === null) {
            return $quoted;
        }

        return $this->dialect->localizedColumn(
            $quoted,
            $localization['locale'],
            $localization['locale'] === $localization['default'],
        );
    }
}
