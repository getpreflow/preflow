<?php

declare(strict_types=1);

namespace Preflow\Tools\Convert;

use Preflow\Data\FieldKinds;
use Preflow\Data\Schema\ColumnKind;

/**
 * Every value of the tables still to convert against its column's kind, and every unique index
 * still to add against duplicates. Reads only.
 */
final class Checker
{
    /** The stored date formats (DateField, Folio's date inputs, offsets as written). */
    public const array DATE_FORMATS = ['Y-m-d', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d\TH:iP', 'Y-m-d H:i:s'];

    public function __construct(private readonly Tables $tables) {}

    /** @return list<Failure> */
    public function check(): array
    {
        $failures = [];
        foreach ($this->tables->pending() as $work) {
            array_push($failures, ...$this->checkTable($work));
        }

        return $failures;
    }

    /** @return list<Failure> */
    private function checkTable(Work $work): array
    {
        $table = $work->table;
        $rows = $this->tables->rows($table);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[$table->idField], (string) $b[$table->idField]));
        $failures = [];
        foreach ($rows as $row) {
            foreach ($work->check as $name => $spec) {
                $value = $row[$name] ?? null;
                $reason = $value === null ? null : self::misfit($spec->kind, (string) $value, $spec->length);
                if ($reason !== null) {
                    $failures[] = new Failure($table->name, (string) $row[$table->idField], $name, (string) $value, $reason);
                }
            }
        }
        foreach ($work->missing as $index) {
            if ($index->unique) {
                array_push($failures, ...$this->duplicates($table, $index, $rows));
            }
        }

        return $failures;
    }

    /** Why the value does not fit the kind, or null when it does. */
    public static function misfit(ColumnKind $kind, string $value, ?int $length): ?string
    {
        return match ($kind) {
            ColumnKind::Number => match (true) {
                $value === '' => null,
                preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1 => 'not a number',
                // A DOUBLE (MySQL) or REAL (SQLite) holds 15 significant digits exactly; more are stored rounded.
                strlen(ltrim((string) preg_replace('/e.*$/', '', Converter::decimal($value)), '-')) > 15 => 'too precise for a number column (more than 15 significant digits)',
                default => null,
            },
            ColumnKind::Boolean => in_array(strtolower($value), ['', '0', '1', 'true', 'false'], true) ? null : "not yes/no ('', 0, 1, true, false)",
            ColumnKind::Date => $value === '' || self::isDate($value) ? null : 'not a date in a stored format',
            ColumnKind::Short => mb_strlen($value) <= ($length ?? FieldKinds::DEFAULT_LENGTH) ? null : 'longer than ' . ($length ?? FieldKinds::DEFAULT_LENGTH) . ' characters',
            ColumnKind::Text => null,
        };
    }

    private static function isDate(string $value): bool
    {
        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records whose value (non-empty) and scope (none of it NULL) another record already has: the
     * unique index would refuse them. Exact, as the index compares; MySQL's utf8mb4_bin ignores
     * trailing spaces.
     *
     * @param list<array<string, mixed>> $rows sorted by id
     * @return list<Failure>
     */
    private function duplicates(Table $table, Index $index, array $rows): array
    {
        [$field] = $index->columns;
        $pad = $this->tables->isMysql();
        $first = [];
        $failures = [];
        foreach ($rows as $row) {
            $values = array_map(static fn (string $c): mixed => $row[$c] ?? null, $index->columns);
            if ($values[0] === null || $values[0] === '' || in_array(null, $values, true)) {
                continue;
            }
            $key = json_encode(array_map(static fn (mixed $v): string => $pad ? rtrim((string) $v, ' ') : (string) $v, $values));
            $id = (string) $row[$table->idField];
            if (!isset($first[$key])) {
                $first[$key] = $id;
                continue;
            }
            $scope = array_slice($index->columns, 1);
            $in = $scope === [] ? '' : ' with the same ' . implode(', ', $scope);
            $failures[] = new Failure($table->name, $id, $field, (string) $values[0], "duplicate of record {$first[$key]}{$in}");
        }

        return $failures;
    }
}
