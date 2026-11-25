<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Add;
use Preflow\Data\Condition;

/**
 * A Condition and the values of a conditional write as SQL of a Dialect: one UPDATE or DELETE of
 * one row, WHERE its id matches AND the condition holds. Everything a value says is bound; the
 * dialect reads stored values (Dialect::wholeNumber() for numbers and "no value", exactText() for
 * texts), so a number column is never compared with '' and a text never by a column's collation.
 * PdoDriver uses it for ConditionalWrites; a SQL driver of another kind may use it too.
 *
 * @since 0.89.0
 */
final class ConditionCompiler
{
    public function __construct(private readonly Dialect $dialect) {}

    /**
     * `UPDATE table SET … WHERE id = ? AND (condition)`: a value is bound as given (a list or
     * object as JSON text), an Add adds to the stored whole number (none counts from 0).
     *
     * @param array<string, mixed> $set at least one field
     * @return array{0: string, 1: list<mixed>}
     */
    public function update(string $table, string $idField, string|int $id, Condition $when, array $set): array
    {
        $bindings = [];
        $assignments = [];
        foreach ($set as $field => $value) {
            $column = $this->dialect->quoteIdentifier((string) $field);
            if ($value instanceof Add) {
                $assignments[] = "{$column} = COALESCE(" . $this->dialect->wholeNumber($column) . ', 0) + ?';
                $bindings[] = $value->amount;
                continue;
            }
            $assignments[] = "{$column} = ?";
            $bindings[] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $value;
        }
        $bindings[] = $id;
        $where = $this->where($when, $bindings);

        return [
            'UPDATE ' . $this->dialect->quoteIdentifier($table) . ' SET ' . implode(', ', $assignments)
                . ' WHERE ' . $this->dialect->quoteIdentifier($idField) . " = ? AND ({$where})",
            $bindings,
        ];
    }

    /**
     * `DELETE FROM table WHERE id = ? AND (condition)`.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function delete(string $table, string $idField, string|int $id, Condition $when): array
    {
        $bindings = [$id];
        $where = $this->where($when, $bindings);

        return [
            'DELETE FROM ' . $this->dialect->quoteIdentifier($table) . ' WHERE ' . $this->dialect->quoteIdentifier($idField) . " = ? AND ({$where})",
            $bindings,
        ];
    }

    /**
     * `SELECT 1 FROM table WHERE id = ? AND (condition)`, as a locking read (Dialect::lockingRead()):
     * whether the row matches now.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function matches(string $table, string $idField, string|int $id, Condition $when): array
    {
        $bindings = [$id];
        $where = $this->where($when, $bindings);

        return [
            'SELECT 1 FROM ' . $this->dialect->quoteIdentifier($table) . ' WHERE ' . $this->dialect->quoteIdentifier($idField)
                . " = ? AND ({$where}) LIMIT 1" . $this->dialect->lockingRead(),
            $bindings,
        ];
    }

    /**
     * The condition as an SQL expression; its values are appended to $bindings.
     *
     * @param list<mixed> $bindings
     */
    public function where(Condition $when, array &$bindings): string
    {
        switch ($when->kind) {
            case Condition::ALL:
            case Condition::ANY:
                if ($when->conditions === []) {
                    return $when->kind === Condition::ALL ? '1 = 1' : '0 = 1';
                }
                $parts = [];
                foreach ($when->conditions as $condition) {
                    $parts[] = '(' . $this->where($condition, $bindings) . ')';
                }

                return implode($when->kind === Condition::ALL ? ' AND ' : ' OR ', $parts);
            case Condition::EMPTY:
                // NULL exactly for NULL and '' (Dialect::wholeNumber()), without comparing a number column with ''.
                return $this->dialect->wholeNumber($this->dialect->quoteIdentifier($when->field)) . ' IS NULL';
            case Condition::FIELD:
                return $this->read($when->field, $when->numbers) . ' ' . self::operator($when->operator) . ' ' . $this->read($when->other, $when->numbers);
            default:
                $column = $this->read($when->field, $when->numbers);
                if ($when->operator === 'IN') {
                    $values = (array) $when->value;
                    if ($values === []) {
                        return '0 = 1';
                    }
                    array_push($bindings, ...$values);

                    return "{$column} IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
                }
                $bindings[] = $when->value;

                return "{$column} " . self::operator($when->operator) . ' ?';
        }
    }

    /** A stored field as a comparison reads it: a whole number, or its exact text. */
    private function read(string $field, bool $numbers): string
    {
        $column = $this->dialect->quoteIdentifier($field);

        return $numbers ? $this->dialect->wholeNumber($column) : $this->dialect->exactText($column);
    }

    private static function operator(string $operator): string
    {
        return $operator === '!=' ? '<>' : $operator;
    }
}
