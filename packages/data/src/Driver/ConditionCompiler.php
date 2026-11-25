<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Add;
use Preflow\Data\Condition;

/**
 * A Condition and the values of a conditional write as SQL of a Dialect: one UPDATE or DELETE of
 * one row, WHERE its id matches AND the condition holds. Everything a value says is bound; the
 * dialect reads stored values (Dialect::wholeNumber() for numbers, exactText() for texts, whose
 * LENGTH() also decides, so trailing spaces count where a collation pads; MySQL's LENGTH() counts
 * bytes, which serves for that, and the connection must be utf8mb4), so a number column is
 * never compared with '' and a text never by a column's collation.
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
        // An Add only while its sum stays a 64-bit whole number (SQLite would store a REAL, MySQL's
        // strict mode fail): out of range the row is not matched and DataManager says why.
        foreach ($set as $field => $value) {
            $bound = $value instanceof Add ? $value->bound() : null;
            if ($bound !== null) {
                $where .= ') AND (COALESCE(' . $this->dialect->wholeNumber($this->dialect->quoteIdentifier((string) $field)) . ", 0) {$bound[0]} ?";
                $bindings[] = $bound[1];
            }
        }

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
     * The values of $set as a condition the row holds once they are written: what a driver checks
     * when a write reported no changed row but may have matched one it left as it was (MySQL
     * without FOUND_ROWS). Null when $set holds an Add that changes the number: such a write always
     * changes its row. A float or a list in $set is not checked (it cannot be compared exactly).
     *
     * @param array<string, mixed> $set
     */
    public static function unchangedBy(array $set): ?Condition
    {
        $same = [];
        foreach ($set as $field => $value) {
            $field = (string) $field;
            if ($value instanceof Add) {
                if ($value->amount !== 0) {
                    return null;
                }
                continue;
            }
            $same[] = match (true) {
                $value === null => Condition::isEmpty($field),
                is_bool($value) => Condition::where($field, '=', (int) $value),
                is_int($value), is_string($value) => Condition::where($field, '=', $value),
                default => Condition::all(),
            };
        }

        return Condition::all(...$same);
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
                // NULL or a text of length 0: never compares a number column with '', and a text of
                // spaces is not empty, also where the collation pads with spaces (MySQL's utf8mb4_bin).
                $column = $this->dialect->quoteIdentifier($when->field);

                return "({$column} IS NULL OR LENGTH(" . $this->dialect->exactText($column) . ') = 0)';
            case Condition::FIELD:
                if (!$when->numbers) {
                    return $this->text($this->read($when->field, false), $when->operator, $this->read($when->other, false), null, $bindings);
                }

                return $this->read($when->field, true) . ' ' . self::operator($when->operator) . ' ' . $this->read($when->other, true);
            default:
                $column = $this->read($when->field, $when->numbers);
                if ($when->operator === 'IN') {
                    $values = (array) $when->value;
                    if ($values === []) {
                        return '0 = 1';
                    }
                    if ($when->numbers) {
                        array_push($bindings, ...$values);

                        return "{$column} IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';
                    }
                    $parts = [];
                    foreach ($values as $value) {
                        $parts[] = $this->text($column, '=', '?', (string) $value, $bindings);
                    }

                    return implode(' OR ', $parts);
                }
                if (is_string($when->value)) {
                    return $this->text($column, $when->operator, '?', $when->value, $bindings);
                }
                $bindings[] = $when->value;

                return "{$column} " . self::operator($when->operator) . ' ?';
        }
    }

    /**
     * Two exact texts compared character by character, as Condition::holds() compares them: where
     * the texts are equal but for trailing spaces (a collation that pads with spaces, MySQL's
     * utf8mb4_bin, calls them equal) their lengths decide, the shorter first. $right is '?' bound
     * to $value at each place it appears, or an expression ($value null).
     *
     * @param list<mixed> $bindings
     */
    private function text(string $left, string $operator, string $right, ?string $value, array &$bindings): string
    {
        $sql = match ($operator) {
            '=' => '({L} = {R} AND LENGTH({L}) = LENGTH({R}))',
            '!=' => '({L} <> {R} OR LENGTH({L}) <> LENGTH({R}))',
            '<', '<=' => "({L} < {R} OR ({L} = {R} AND LENGTH({L}) {$operator} LENGTH({R})))",
            default => "({L} > {R} OR ({L} = {R} AND LENGTH({L}) {$operator} LENGTH({R})))",
        };
        if ($value !== null) {
            for ($i = substr_count($sql, '{R}'); $i > 0; $i--) {
                $bindings[] = $value;
            }
        }

        return strtr($sql, ['{L}' => $left, '{R}' => $right]);
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
