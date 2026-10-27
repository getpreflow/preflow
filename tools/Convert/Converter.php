<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/**
 * Converts the tables still to convert, one at a time, so a rerun after a run killed in between
 * continues with the tables not typed yet. '' becomes NULL in number, yes/no, date and unique
 * columns. Each table is re-read after its change: every value must read back equal to the
 * original (numbers numerically, text exactly).
 *
 * SQLite: the table is rebuilt in one transaction (create typed, copy, swap, indexes), so it is
 * either converted or as it was.
 * MySQL: '' and the yes/no words are first normalised in place ('' to NULL, true/false to 1/0:
 * the same values, which a rerun accepts), then one ALTER TABLE retypes the columns and adds the
 * indexes together.
 */
final class Converter
{
    public function __construct(private readonly Tables $tables) {}

    /** @param \Closure(string): void $log told after each table: "converted {table} ({n} rows)" */
    public function convert(\Closure $log): void
    {
        foreach ($this->tables->pending() as $work) {
            $before = $this->byId($work->table, $this->tables->rows($work->table));
            $this->tables->isMysql() ? $this->alter($work, $before) : $this->rebuild($work, $before);
            $count = count($before);
            $log("converted {$work->table->name} ({$count} " . ($count === 1 ? 'row' : 'rows') . ')');
        }
    }

    /** @param array<string, array<string, mixed>> $before */
    private function rebuild(Work $work, array $before): void
    {
        $pdo = $this->tables->pdo;
        $g = $this->tables->grammar;
        $table = $work->table;
        $new = $table->name . '__converting';
        $planned = array_map(static fn (Index $i): string => $i->name, $table->indexes);
        // Only over existing columns: a field added since gets its column and index from migrate.
        $indexes = array_filter($table->indexes, static fn (Index $i): bool => array_diff($i->columns, $work->columns) === []);
        $own = $pdo->prepare("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL");
        $own->execute([$table->name]);
        $others = array_filter($own->fetchAll(\PDO::FETCH_KEY_PAIR), static fn (string $name): bool => !in_array($name, $planned, true), ARRAY_FILTER_USE_KEY);

        $specs = array_map(static fn (string $c): ColumnSpec => $table->specs[$c] ?? new ColumnSpec($c), $work->columns);
        $columns = implode(', ', array_map($g->quote(...), [$table->idField, ...$work->columns]));
        $values = implode(', ', [$g->quote($table->idField), ...array_map(fn (ColumnSpec $s): string => $this->value($s, $table), $specs)]);

        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE IF EXISTS ' . $g->quote($new));
            $pdo->exec($g->createRecordTable($new, $table->idField, $specs));
            $pdo->exec('INSERT INTO ' . $g->quote($new) . " ({$columns}) SELECT {$values} FROM " . $g->quote($table->name));
            $pdo->exec('DROP TABLE ' . $g->quote($table->name));
            $pdo->exec('ALTER TABLE ' . $g->quote($new) . ' RENAME TO ' . $g->quote($table->name));
            foreach ($indexes as $index) {
                $pdo->exec($g->createRecordIndex($index->name, $table->name, $index->columns, $index->unique));
            }
            foreach ($others as $sql) {
                $pdo->exec($sql);
            }
            $this->recheck($table, $before);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** The copied value of a column: '' to NULL where it is stored so, yes/no words to 1 and 0. */
    private function value(ColumnSpec $spec, Table $table): string
    {
        $c = $this->tables->grammar->quote($spec->name);
        if ($spec->kind === ColumnKind::Boolean) {
            return "CASE WHEN {$c} IS NULL OR {$c} = '' THEN NULL WHEN LOWER({$c}) IN ('1', 'true') THEN 1 ELSE 0 END";
        }

        return in_array($spec->name, $table->emptyToNull, true) ? "NULLIF({$c}, '')" : $c;
    }

    /** @param array<string, array<string, mixed>> $before */
    private function alter(Work $work, array $before): void
    {
        $pdo = $this->tables->pdo;
        $g = $this->tables->grammar;
        $table = $work->table;
        $t = $g->quote($table->name);
        foreach ($work->empties as $column) {
            $c = $g->quote($column);
            $pdo->exec("UPDATE {$t} SET {$c} = NULL WHERE CHAR_LENGTH({$c}) = 0");
        }
        $clauses = [];
        foreach ($work->retype as $column) {
            $spec = $table->specs[$column];
            $c = $g->quote($column);
            if ($spec->kind === ColumnKind::Boolean) {
                $pdo->exec("UPDATE {$t} SET {$c} = CASE WHEN LOWER({$c}) IN ('1', 'true') THEN '1' ELSE '0' END WHERE {$c} IS NOT NULL");
            }
            $clauses[] = "MODIFY {$c} " . $g->recordColumnType($spec->kind, $spec->length);
        }
        foreach ($work->missing as $index) {
            $clauses[] = 'ADD ' . ($index->unique ? 'UNIQUE ' : '') . 'INDEX ' . $g->quote($index->name)
                . ' (' . implode(', ', array_map($g->quote(...), $index->columns)) . ')';
        }
        if ($clauses !== []) {
            $pdo->exec("ALTER TABLE {$t} " . implode(', ', $clauses));
        }
        $this->recheck($table, $before);
    }

    /**
     * Every value reads back as it was: NULL for NULL and for '' turned NULL, numbers equal as
     * numbers, yes/no as 0 or 1, everything else the same text.
     *
     * @param array<string, array<string, mixed>> $before
     */
    private function recheck(Table $table, array $before): void
    {
        $after = $this->byId($table, $this->tables->rows($table));
        if (array_keys($after) != array_keys($before)) {
            throw new \RuntimeException("{$table->name}: the converted table does not hold the same records.");
        }
        foreach ($before as $id => $row) {
            foreach ($row as $column => $was) {
                $now = $after[$id][$column] ?? null;
                if (!self::same($table->specs[$column]->kind ?? ColumnKind::Text, in_array($column, $table->emptyToNull, true), $was, $now)) {
                    throw new \RuntimeException(sprintf(
                        '%s %s %s: %s reads back as %s.',
                        $table->name, $id, $column, var_export($was, true), var_export($now, true),
                    ));
                }
            }
        }
    }

    private static function same(ColumnKind $kind, bool $emptyToNull, mixed $was, mixed $now): bool
    {
        if ($was === null || ($was === '' && ($emptyToNull || $kind === ColumnKind::Boolean))) {
            return $now === null;
        }
        if ($now === null) {
            return false;
        }

        return match ($kind) {
            ColumnKind::Number => is_numeric($now) && is_numeric($was) && self::decimal($was) === self::decimal($now),
            ColumnKind::Boolean => (int) $now === (in_array(strtolower((string) $was), ['1', 'true'], true) ? 1 : 0),
            default => (string) $was === (string) $now,
        };
    }

    /**
     * A number's exact decimal value in one normal form: sign, significant digits without
     * leading or trailing zeros, and the power of ten ('12.50' is "125e-1", '1.2E+19' "12e18",
     * '-0' "0"). A float is read with 15 significant digits, all a double holds exactly, so a
     * value that lost digits on the way in reads back different.
     */
    public static function decimal(int|float|string $value): string
    {
        $text = is_float($value) ? sprintf('%.15g', $value) : trim((string) $value);
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $text, $m) !== 1 || ($m[2] ?? '') . ($m[3] ?? '') === '') {
            throw new \InvalidArgumentException("Not a number: {$text}");
        }
        $fraction = $m[3] ?? '';
        $digits = ltrim($m[2] . $fraction, '0');
        if ($digits === '') {
            return '0';
        }
        $exponent = (int) ($m[4] ?? 0) - strlen($fraction);
        $trimmed = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($trimmed);

        return ($m[1] === '-' ? '-' : '') . $trimmed . ($exponent !== 0 ? "e{$exponent}" : '');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function byId(Table $table, array $rows): array
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row[$table->idField]] = $row;
        }
        ksort($byId, SORT_STRING);

        return $byId;
    }
}
