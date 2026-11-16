<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Grammar\ConvertsCollations;
use Preflow\Data\Query;
use Preflow\Data\Text\TextMatch;
use Preflow\Data\TypeDefinition;

/**
 * The values that are there more than once under the text rule (ignore case, respect accents) in
 * a unique field and its scope: what `preflow migrate` lists before it gives a column the rule
 * (RecordTableSync), and on storage without unique indexes (JSON). Each group is one value and
 * the ids of the records holding it, ordered by id; an empty value (null) never collides.
 * Nothing is changed.
 *
 * @since 0.78
 */
final class TextDuplicates
{
    /** Group lines a report lists at most; the rest are counted (lines()). */
    public const int LISTED = 20;

    /**
     * The duplicate groups in a SQL table when its columns compare by the given collations: one
     * GROUP BY of the columns, each under its collation (none named: as the column has it) and
     * by the prefix a unique index keeps of it, with HAVING COUNT(*) > 1, joined back to the rows
     * for their ids.
     *
     * @param string|null $idField the column naming a row in the report (null: ConvertsCollations::rowKey())
     * @param list<string> $columns the unique column first, then its scope
     * @param array<string, ?string> $collations column => the collation to compare it in (missing or null: its own)
     * @param array<string, int> $prefixes column => the characters a prefix index keeps of it
     * @return list<array{value: string, ids: list<string>}>
     */
    public static function sql(\PDO $pdo, SchemaGrammar&ConvertsCollations $grammar, string $table, ?string $idField, array $columns, array $collations, array $prefixes = []): array
    {
        $q = $grammar->quote(...);
        $idField ??= $grammar->rowKey($pdo, $table);
        $in = static function (string $alias, string $column) use ($q, $grammar, $collations, $prefixes): string {
            $sql = $alias . '.' . $q($column);
            if (isset($prefixes[$column])) {
                $sql = $grammar->prefix($sql, $prefixes[$column]);
            }

            return ($collations[$column] ?? null) !== null ? $grammar->collate($sql, (string) $collations[$column]) : $sql;
        };
        $columns = array_values($columns);
        $keys = [];
        $present = [];
        $on = [];
        foreach ($columns as $i => $column) {
            $keys[] = $in('s', $column) . ' AS ' . $q("k{$i}");
            $present[] = 's.' . $q($column) . ' IS NOT NULL';
            $on[] = $in('t', $column) . ' = d.' . $q("k{$i}");
        }
        $keyNames = implode(', ', array_map(static fn (int $i): string => 'd.' . $q("k{$i}"), array_keys($columns)));
        $groupBy = implode(', ', array_map(static fn (string $column): string => $in('s', $column), $columns));
        $sql = 'SELECT t.' . $q($idField) . ' AS ' . $q('id') . ', t.' . $q($columns[0]) . ' AS ' . $q('value') . ', ' . $keyNames
            . ' FROM ' . $q($table) . ' t JOIN (SELECT ' . implode(', ', $keys) . ' FROM ' . $q($table) . ' s WHERE ' . implode(' AND ', $present)
            . ' GROUP BY ' . $groupBy . ' HAVING COUNT(*) > 1) d ON ' . implode(' AND ', $on)
            . ' ORDER BY ' . $keyNames . ', t.' . $q($idField);

        $groups = [];
        $statement = $pdo->query($sql);
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            [$id, $value] = $row;
            $groups[serialize(array_slice($row, 2))][(string) $id] = (string) $value;
        }
        $statement->closeCursor();

        return self::found($groups);
    }

    /**
     * The duplicate groups in a SQL table whose index cannot be trusted to find them (one built
     * under another version of the rule): every row read in table order, without a condition an
     * index could serve, and grouped here, a column under the rule (ConvertsCollations::isTextCollation()
     * of its collation) by TextMatch::key(), any other by its exact value. For a collation the
     * application supplies (SQLite's preflow_text is TextMatch::compare()), so this is the rule
     * the column compares by.
     *
     * @param list<string> $columns the unique column first, then its scope
     * @return list<array{value: string, ids: list<string>}>
     */
    public static function scan(\PDO $pdo, SchemaGrammar&ConvertsCollations $grammar, string $table, ?string $idField, array $columns): array
    {
        $idField ??= $grammar->rowKey($pdo, $table);
        $collations = $grammar->columnCollations($pdo, $table);
        $q = $grammar->quote(...);
        $statement = $pdo->query('SELECT ' . $q($idField) . ', ' . implode(', ', array_map($q, $columns)) . ' FROM ' . $q($table));
        $groups = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $id = (string) array_shift($row);
            $key = [];
            foreach ($columns as $i => $column) {
                if ($row[$i] === null) {
                    continue 2;
                }
                $key[] = $grammar->isTextCollation($collations[$column] ?? null) ? TextMatch::key((string) $row[$i]) : (string) $row[$i];
            }
            $groups[serialize($key)][$id] = (string) $row[0];
        }
        $statement->closeCursor();

        return self::found($groups);
    }

    /**
     * The duplicate groups of a unique text field in the stored records (any storage, read through
     * its driver): records grouped by TextMatch::key() of the field and of each scope field that
     * is text, the exact value of any other scope field. A translatable field is checked per
     * locale ($locale, its value in the locale map). A field that is not text (TextColumns) has
     * none: its unique check is exact.
     *
     * @return list<array{value: string, ids: list<string>}>
     */
    public static function records(DataManager $dm, TypeDefinition $def, string $field, ?string $locale = null): array
    {
        $text = TextColumns::of($def, $dm->fieldKinds());
        if (!in_array($field, $text, true)) {
            return [];
        }
        $scope = $def->uniqueFields()[$field] ?? [];
        $groups = [];
        foreach ($dm->driver($def->storage)->findMany($def->table, new Query())->items() as $row) {
            $value = $row[$field] ?? null;
            if ($locale !== null) {
                $value = is_string($value) ? json_decode($value, true) : $value;
                $value = is_array($value) ? ($value[$locale] ?? null) : null;
            }
            if (!is_scalar($value) || $value === '') {
                continue;
            }
            $key = [TextMatch::key((string) $value)];
            foreach ($scope as $name) {
                $scoped = $row[$name] ?? null;
                if (!is_scalar($scoped)) {
                    continue 2; // a null in the scope never collides
                }
                $key[] = in_array($name, $text, true) ? TextMatch::key((string) $scoped) : (string) $scoped;
            }
            $id = (string) ($row[$def->idField] ?? '');
            $groups[serialize($key)][$id] = (string) $value;
        }

        return self::found($groups);
    }

    /**
     * @param array<string, array<string, string>> $groups key => id => stored value
     * @return list<array{value: string, ids: list<string>}> the groups of more than one, each with the value of its first id
     */
    private static function found(array $groups): array
    {
        $found = [];
        foreach ($groups as $members) {
            if (count($members) > 1) {
                ksort($members, SORT_STRING);
                $found[] = ['value' => (string) reset($members), 'ids' => array_map('strval', array_keys($members))];
            }
        }

        return self::byId($found);
    }

    /**
     * The report lines of the groups: a first line, then one line each for up to LISTED groups
     * ('  "value" in id, id'), then '  … and n more'.
     *
     * @param list<array{value: string, ids: list<string>}> $groups
     * @return list<string>
     */
    public static function lines(string $first, array $groups): array
    {
        $lines = [$first];
        foreach (array_slice($groups, 0, self::LISTED) as $group) {
            $lines[] = '  "' . str_replace(["\r", "\n"], ' ', $group['value']) . '" in ' . implode(', ', $group['ids']);
        }
        if (count($groups) > self::LISTED) {
            $lines[] = '  … and ' . (count($groups) - self::LISTED) . ' more';
        }

        return $lines;
    }

    /** "1 value is", "n values are". */
    public static function counted(int $groups): string
    {
        return $groups . ($groups === 1 ? ' value is' : ' values are');
    }

    /**
     * Groups in the order of their first id.
     *
     * @param list<array{value: string, ids: list<string>}> $groups
     * @return list<array{value: string, ids: list<string>}>
     */
    private static function byId(array $groups): array
    {
        usort($groups, static fn (array $a, array $b): int => strcmp($a['ids'][0], $b['ids'][0]));

        return $groups;
    }
}
