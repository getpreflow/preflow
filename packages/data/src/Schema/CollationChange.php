<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\Migration\Grammar\FullTextIndexes;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Grammar\ConvertsCollations;

/**
 * Gives some columns of one existing table a new collation (the text rule's or the exact one)
 * the way `preflow migrate` does it everywhere (RecordTableSync for record tables,
 * TextRuleConversion for declared and recorded tables): never changing or removing a value.
 *
 * Every unique index over a column whose collation changes is counted first, whatever the old
 * collation (TextDuplicates::sql(), its columns under their new collations and by its prefixes):
 * a utf8mb4_bin column may hold "Müller" and "MÜLLER", and an older case-insensitive one
 * (utf8mb4_unicode_ci) values the rule takes as one ("ẞ" and "ß"). When some values become equal,
 * the changing columns of that index are left as they are (and the other columns of a FULLTEXT
 * index they share, which takes one collation), every group is listed ("refused:"), and the rest
 * changes in one statement (ConvertsCollations::convertCollations()). When the database refuses the
 * statement (a save added a duplicate since the count), nothing changes.
 *
 * The table's default collation (where the database has one: ConvertsCollations::tableCollation())
 * becomes the rule's too, as a table made since 0.78 has it, so a column added later without
 * naming a collation compares by the rule rather than by an older one. That changes metadata
 * only, and waits for the next run when the columns' statement was refused.
 *
 * @since 0.78
 */
final class CollationChange
{
    /**
     * @param string|null $idField the column naming a row in the lists (null: ConvertsCollations::rowKey())
     * @param array<string, array{type: string, text: bool}> $changes column => its new full type, and whether that is the rule's collation
     * @param bool $dryRun only count and list ("refused:" lines); change nothing (`migrate --pretend`)
     * @return list<string> the refusals and the changes made, one line each
     */
    public static function apply(\PDO $pdo, SchemaGrammar&ConvertsCollations $grammar, string $table, ?string $idField, array $changes, bool $dryRun = false): array
    {
        [$lines, $refusedByServer] = self::columns($pdo, $grammar, $table, $idField, $changes, $dryRun);
        if ($dryRun || $refusedByServer || $grammar->textCollation() === null) {
            return $lines;
        }
        $default = $grammar->tableCollation($pdo, $table);
        if ($default !== null && !$grammar->isTextCollation($default)) {
            $grammar->convertTableCollation($pdo, $table);
            $lines[] = "converted the default collation of {$table} to {$grammar->textCollation()}";
        }

        return $lines;
    }

    /**
     * @param array<string, array{type: string, text: bool}> $changes
     * @return array{0: list<string>, 1: bool} the lines, and whether the database refused the statement
     */
    private static function columns(\PDO $pdo, SchemaGrammar&ConvertsCollations $grammar, string $table, ?string $idField, array $changes, bool $dryRun): array
    {
        if ($changes === []) {
            return [[], false];
        }
        $current = $grammar->columnCollations($pdo, $table);
        $indexColumns = $grammar->indexColumns($pdo, $table);
        $prefixes = $grammar->uniquePrefixes($pdo, $table);
        $lines = [];
        $refused = [];
        foreach ($grammar->uniqueIndexes($pdo, $table) as $index) {
            $columns = $indexColumns[$index] ?? [];
            $changing = array_values(array_diff(array_intersect($columns, array_keys($changes)), $refused));
            if ($changing === []) {
                continue;
            }
            $collations = [];
            foreach ($columns as $column) {
                $collations[$column] = in_array($column, $changing, true)
                    ? ($changes[$column]['text'] ? $grammar->textCollation() : $grammar->exactCollation())
                    : $current[$column] ?? null;
            }
            $groups = TextDuplicates::sql($pdo, $grammar, $table, $idField, $columns, $collations, $prefixes[$index] ?? []);
            if ($groups === []) {
                continue;
            }
            array_push($lines, ...TextDuplicates::lines(
                "refused: {$table}." . implode(', ', $changing) . (count($columns) > 1 ? ' (unique together: ' . implode(', ', $columns) . ')' : '') . ': ' . TextDuplicates::counted(count($groups))
                    . ' there more than once when case is ignored; the column keeps its collation until they differ',
                $groups,
            ));
            array_push($refused, ...$changing);
        }
        if ($refused !== [] && $grammar instanceof FullTextIndexes) {
            foreach ($grammar->fullTextIndexes($pdo, $table) as $over) {
                if (array_intersect($over, $refused) !== []) {
                    array_push($refused, ...$over); // one collation over a FULLTEXT index's columns
                }
            }
        }
        $plan = array_map(static fn (array $change): string => $change['type'], array_diff_key($changes, array_flip($refused)));
        if ($dryRun || $plan === []) {
            return [$lines, false];
        }
        try {
            $grammar->convertCollations($pdo, $table, $plan);
        } catch (\PDOException $e) {
            // A save wrote a duplicate since the count (MySQL refuses the ALTER, 1062): nothing changed.
            $lines[] = "refused: {$table}: the server refused the conversion ({$e->getMessage()}); run migrate again";

            return [$lines, true];
        } catch (\LogicException $e) {
            // The table cannot be rebuilt as it is (an SQLite constraint the rebuild cannot keep): nothing changed.
            $lines[] = "refused: {$table}: " . rtrim($e->getMessage(), '.') . '; convert the table (see the data README)';

            return [$lines, true];
        }
        $lines[] = "converted collations of {$table}: " . implode(', ', array_keys($plan));

        return [$lines, false];
    }
}
