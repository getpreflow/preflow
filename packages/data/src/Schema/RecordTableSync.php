<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\PdoConnection;
use Preflow\Data\RemembersColumns;
use Preflow\Data\FieldKinds;
use Preflow\Data\Migration\Grammar\FullTextIndexes;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Grammar\ConvertsCollations;
use Preflow\Data\Migration\Grammar\TextCollations;
use Preflow\Data\Migration\MigrationColumns;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Search\LooseFields;
use Preflow\Data\TypeDefinition;

/**
 * The table of one SQL-stored type: created when missing, missing columns added. The id is the
 * primary key; every other column is nullable and typed by its field's column kind (FieldKinds),
 * or free text when the table is untyped. A declared index (a field's, or a composite one) is
 * made whenever it is missing, on new and existing columns alike; a unique index too, but only
 * over columns of their expected type that hold no value twice. An existing column's type is
 * never altered, so a column whose type differs from its kind (or a unique field without its
 * index) is reported as a warning naming the column to convert (data README, "Converting record
 * tables"). Since 0.78 an existing typed table's columns get the collation of their kind (text
 * fields the text rule's, the rest exact; collations()), listing and leaving exact a unique
 * column whose values would collide. Nothing is ever dropped; JSON-stored definitions are left
 * alone.
 *
 * @phpstan-import-type Entry from RecordTables
 */
final class RecordTableSync
{
    private const string CONVERT = 'convert the table (see the data README)';

    public function __construct(private readonly DataManager $dm) {}

    /**
     * @param list<Entry> $entries
     * @return list<string> the changes made and the warnings, one line each
     */
    public function syncAll(array $entries): array
    {
        $lines = [];
        foreach ($entries as $entry) {
            array_push($lines, ...$this->sync($entry['def'], $entry['extra'], $entry['typed'], $entry['unique']));
        }

        return $lines;
    }

    /**
     * @param list<string> $extraColumns columns besides the definition's fields (free text)
     * @param bool $typed false: every column free text and no indexes (history tables)
     * @param bool $unique false: no unique indexes (tables holding copies of records)
     * @return list<string> the changes made and the warnings, one line each
     */
    public function sync(TypeDefinition $def, array $extraColumns = [], bool $typed = true, bool $unique = true): array
    {
        try {
            return $this->syncTable($def, $extraColumns, $typed, $unique);
        } finally {
            $this->forgetColumns($def);
        }
    }

    /** A driver that remembers column facts is told the table may have changed (RemembersColumns). */
    private function forgetColumns(TypeDefinition $def): void
    {
        $driver = $this->dm->driver($def->storage);
        if ($driver instanceof RemembersColumns) {
            $driver->forgetColumns($def->table);
        }
    }

    /** @return list<string> */
    private function syncTable(TypeDefinition $def, array $extraColumns, bool $typed, bool $unique): array
    {
        $driver = $this->dm->driver($def->storage);
        if (!$driver instanceof PdoConnection) {
            return [];
        }

        $pdo = $driver->pdo();
        $schema = new Schema($pdo);
        $grammar = Grammars::for($pdo);
        $specs = $this->specs($def, $extraColumns, $typed, $grammar);

        $lines = [];
        $drifted = [];
        $types = [];
        $existed = $schema->hasTable($def->table);
        if (!$existed) {
            $pdo->exec($grammar->createRecordTable($def->table, $def->idField, array_values($specs)));
            $lines[] = "created table {$def->table}";
        } else {
            $types = $schema->columnTypes($def->table);
            foreach ($specs as $name => $spec) {
                $expected = $spec->text && $grammar instanceof TextCollations
                    ? $grammar->textColumnType($spec->kind, $spec->length)
                    : $grammar->recordColumnType($spec->kind, $spec->length);
                if (!isset($types[$name])) {
                    $schema->addColumn($def->table, $name, $expected);
                    $lines[] = "added column {$def->table}.{$name}";
                } elseif ($types[$name] !== self::reported($expected)) {
                    $lines[] = "warning: {$def->table}.{$name} is {$types[$name]}, expected {$spec->kind->value}; " . self::CONVERT;
                    $drifted[] = $name;
                }
            }
        }
        if ($existed) {
            array_push($lines, ...$this->collations($def, $extraColumns, $typed));
        }
        if (!$typed) {
            return $lines;
        }

        $existing = $grammar->indexColumns($pdo, $def->table);
        $uniques = $grammar->uniqueIndexes($pdo, $def->table);
        foreach (self::indexes($grammar, $def, $specs, $unique) as $index) {
            $over = implode(', ', $index->columns);
            if ($index->freeText !== null) {
                $lines[] = "warning: {$def->table}: no index over {$over} ({$index->freeText} is free text); see the data README";
                continue;
            }
            if ($index->tooLongFor($grammar)) {
                $lines[] = "warning: {$def->table}: no index over {$over} (a key of up to {$index->bytes} bytes; MySQL allows "
                    . RecordIndex::MYSQL_MAX_KEY_BYTES . '); give its text fields a shorter "length"';
                continue;
            }
            if (self::found($index, $existing, $uniques)) {
                continue;
            }
            if (!$index->unique) {
                // An existing column may be TEXT (made before it was indexed): the grammar indexes a prefix where it must.
                try {
                    $pdo->exec($grammar->createRecordIndex($index->name, $def->table, $index->columns, false, $types, self::lengths($specs)));
                } catch (\PDOException $e) {
                    $lines[] = "warning: {$def->table}: no index over {$over} (the server refused it: {$e->getMessage()}); see the data README";
                    continue;
                }
                $lines[] = "added index {$index->name}";
                $existing[$index->name] = $index->columns;
                continue;
            }
            $field = $index->columns[0];
            // Only over columns of the expected type, and only when no value is there twice.
            if (array_intersect($index->columns, $drifted) !== []) {
                $lines[] = "warning: {$def->table}.{$field} has no unique index; " . self::CONVERT;
                continue;
            }
            $duplicates = self::duplicates($pdo, $grammar, $def->table, $index->columns);
            if ($duplicates > 0) {
                $lines[] = "warning: {$def->table}.{$field} has no unique index ({$duplicates} " . ($duplicates === 1 ? 'value is' : 'values are')
                    . ' there more than once); remove the duplicates (tools/convert-typed-columns.php --check lists them), the next migrate adds it';
                continue;
            }
            try {
                $pdo->exec($grammar->createRecordIndex($index->name, $def->table, $index->columns, true));
            } catch (\PDOException) {
                // A save wrote a duplicate since the count: the next migrate counts again.
                $lines[] = "warning: {$def->table}.{$field} has no unique index; " . self::CONVERT;
                continue;
            }
            $lines[] = "added unique index {$index->name}";
            $existing[$index->name] = $index->columns;
            $uniques[] = $index->name;
        }
        array_push($lines, ...$this->fullText($def, $pdo, $grammar));

        return $lines;
    }

    /**
     * The collation pass (since 0.78; the data README's "Existing tables (`preflow migrate`)"):
     * each column of the table gets the collation plan() gives it, text fields the rule's
     * (TextCollations), every other column (also one the entry does not list, such as a system
     * column an earlier version made) the exact one, all of the table's in one statement
     * (CollationChange: unique indexes counted first, a column whose values would collide left as
     * it is and listed), then the table default the rule's (CollationChange). A column already
     * under any name of the rule (or exact) stays. Drifted and
     * missing columns are left out, and so is one the grammar cannot change without changing its
     * values (another character set: a warning). Nothing with a grammar that does not convert
     * (no ConvertsCollations; TextRuleConversion says so once per storage). Public for `migrate
     * --pretend` (TextRuleConversion::check(); $dryRun: only the refusals, nothing changed).
     *
     * @internal
     * @param list<string> $extraColumns
     * @return list<string>
     */
    public function collations(TypeDefinition $def, array $extraColumns = [], bool $typed = true, bool $dryRun = false): array
    {
        try {
            return $this->convertCollations($def, $extraColumns, $typed, $dryRun);
        } finally {
            $this->forgetColumns($def);
        }
    }

    /** @return list<string> */
    private function convertCollations(TypeDefinition $def, array $extraColumns, bool $typed, bool $dryRun): array
    {
        $driver = $this->dm->driver($def->storage);
        if (!$driver instanceof PdoConnection) {
            return [];
        }
        $pdo = $driver->pdo();
        $grammar = Grammars::for($pdo);
        if (!$grammar instanceof ConvertsCollations || $grammar->textCollation() === null || !$grammar->hasTable($pdo, $def->table)) {
            return [];
        }
        $current = $grammar->columnCollations($pdo, $def->table);
        $types = $grammar->columnTypes($pdo, $def->table);
        $lines = [];
        $changes = [];
        foreach ($this->plan($pdo, $grammar, $def, $extraColumns, $typed, $current) as $name => $column) {
            $collation = $current[$name] ?? null;
            if ($collation === null) {
                continue; // not there yet (the sync adds it), or no text (numbers)
            }
            [$text, $spec] = $column;
            if ($text === null) {
                if ($grammar->isTextCollation($collation) || $grammar->isExactCollation($collation)) {
                    continue; // no stated intent, and it follows the rule or compares exactly already
                }
                $text = false;
            }
            if ($spec !== null) {
                $type = $text ? $grammar->textColumnType($spec->kind, $spec->length) : $grammar->recordColumnType($spec->kind, $spec->length);
                if (($types[$name] ?? null) !== self::reported($type)) {
                    continue; // drift: the sync warns, the table is converted on purpose
                }
            } else {
                $type = $grammar->withCollation($types[$name], $text); // its own type, only the collation changes
            }
            if ($text ? $grammar->isTextCollation($collation) : $grammar->isExactCollation($collation)) {
                continue;
            }
            $refusal = $grammar->collationRefusal($types[$name], $collation);
            if ($refusal !== null) {
                $lines[] = "warning: {$def->table}.{$name} is {$refusal}; it keeps its collation (convert the table, see the data README)";
                continue;
            }
            $changes[$name] = ['type' => $type, 'text' => $text];
        }

        return [...$lines, ...CollationChange::apply($pdo, $grammar, $def->table, $def->idField, $changes, $dryRun)];
    }

    /**
     * The full column plan of an existing record table, the one place that decides how each of its
     * columns compares (since 0.78): column => [true for the text rule / false for exact, its
     * ColumnSpec or null]. The definition's columns (specs(): text fields the rule, every other
     * column exact) and then every other column the table holds: the id (exact), a column a
     * migration added with a recorded intent (MigrationColumns: that intent), and any other one
     * (a system column such as _locales, _order, _share_token whichever package wrote it, listed
     * by its entry or not, or the column of a field since removed: null, it keeps the rule or the
     * exact collation it has, and an older collation becomes exact). So a package lists only the
     * columns it makes, never the ones a conversion has to find.
     *
     * @param list<string> $extraColumns
     * @param array<string, ?string> $current the table's columns (ConvertsCollations::columnCollations())
     * @return array<string, array{0: ?bool, 1: ?ColumnSpec}>
     */
    private function plan(\PDO $pdo, SchemaGrammar $grammar, TypeDefinition $def, array $extraColumns, bool $typed, array $current): array
    {
        $plan = [];
        foreach ($this->specs($def, $extraColumns, $typed, $grammar) as $name => $spec) {
            $plan[$name] = [$spec->text, $spec];
        }
        $others = array_diff(array_keys($current), array_keys($plan));
        if ($others === []) {
            return $plan;
        }
        $bookkeeping = new MigrationColumns($pdo);
        $recorded = $bookkeeping->exists() ? $bookkeeping->tables()[$def->table] ?? [] : [];
        foreach ($others as $name) {
            $name = (string) $name;
            $plan[$name] = [match (true) {
                $name === $def->idField => false,
                isset($recorded[$name]) => $recorded[$name] === MigrationColumns::TEXT,
                default => null,
            }, null];
        }

        return $plan;
    }

    /**
     * The FULLTEXT index of a type that declares full-text search, on a grammar that has them: over
     * exactly the loosely searchable text fields (Search\LooseFields::text()), made when no FULLTEXT
     * index covers that set of columns, replacing the one of the same name that covers another set
     * (a text field was added or left out). Nothing is made for a type without text fields.
     *
     * @return list<string>
     */
    private function fullText(TypeDefinition $def, \PDO $pdo, SchemaGrammar $grammar): array
    {
        if (!$def->fullText || !$grammar instanceof FullTextIndexes) {
            return [];
        }
        $columns = LooseFields::text($def, $this->dm->fieldKinds());
        if ($columns === []) {
            return [];
        }
        $name = $grammar->indexName('ft', $def->table, 'search');
        $wanted = $columns;
        sort($wanted);
        $lines = [];
        foreach ($grammar->fullTextIndexes($pdo, $def->table) as $existing => $over) {
            sort($over);
            if ($over === $wanted) {
                return [];
            }
            if ($existing === $name) {
                $pdo->exec($grammar->dropIndex($def->table, $name));
                $lines[] = "dropped full-text index {$name}";
            }
        }
        try {
            $pdo->exec($grammar->createFullTextIndex($name, $def->table, $columns));
        } catch (\PDOException $e) {
            $lines[] = "warning: {$def->table}: no full-text index over " . implode(', ', $columns) . " (the server refused it: {$e->getMessage()}); see the data README";

            return $lines;
        }
        $lines[] = "added full-text index {$name}";

        return $lines;
    }

    /**
     * The columns besides the id, each with its kind: the field's (a scope field of a unique
     * field and a field of a composite index count as indexed), free text for extra columns and
     * in untyped tables. A text field's column (TextColumns) is marked text, in untyped tables
     * too (they hold copies of the records, queried by the same type), so it compares by the
     * text rule; every other column exactly. On a grammar with full-text indexes the exact fields
     * and lists a full-text type's index covers (TextColumns::exactInFullText()) take the rule's
     * collation too, as the index needs one collation over its columns; queries compare them
     * exactly (Query::compareExactly()). Public: the conversion of existing tables gives them the
     * same columns.
     *
     * @internal
     * @param list<string> $extraColumns
     * @param SchemaGrammar|null $grammar the table's grammar (null: no full-text index considered)
     * @return array<string, ColumnSpec>
     */
    public function specs(TypeDefinition $def, array $extraColumns, bool $typed, ?SchemaGrammar $grammar = null): array
    {
        $indexed = $def->indexedByOthers();
        $kinds = $this->dm->fieldKinds();
        $text = array_fill_keys([
            ...TextColumns::of($def, $kinds),
            ...($typed && $grammar instanceof FullTextIndexes ? TextColumns::exactInFullText($def, $kinds) : []),
        ], true);
        $specs = [];
        foreach ($def->fields as $name => $field) {
            if (!$typed) {
                $specs[$name] = new ColumnSpec($name, text: isset($text[$name]));
                continue;
            }
            if (in_array($name, $indexed, true) && !$field->index) {
                $field = $field->withIndex();
            }
            $specs[$name] = new ColumnSpec($name, $this->dm->fieldKinds()->columnKindOf($field), $field->length, isset($text[$name]));
        }
        foreach ($extraColumns as $name) {
            $specs[$name] ??= new ColumnSpec($name);
        }
        unset($specs[$def->idField]);

        return $specs;
    }

    /**
     * The indexes a typed sync gives a table: unique over each unique field and its scope (unless
     * $unique is false; one over a translatable or free-text column is checked on save instead and
     * not listed), then plain over each indexed field and each composite index (one over a
     * free-text column is listed with that column as freeText: sync warns about it). Each carries
     * the most bytes its key takes on MySQL, from the columns' kinds: sync warns about one past the
     * limit there (RecordIndex::tooLongFor()). Public: the conversion of existing tables makes the
     * same ones.
     *
     * @param array<string, ColumnSpec> $specs specs()
     * @return list<RecordIndex>
     */
    public static function indexes(SchemaGrammar $grammar, TypeDefinition $def, array $specs, bool $unique): array
    {
        $indexes = [];
        if ($unique) {
            foreach ($def->uniqueFields() as $field => $scope) {
                $columns = [$field, ...$scope];
                if (self::freeText($specs, $columns) === null) {
                    $indexes[] = new RecordIndex($grammar->indexName('uniq', $def->table, $field), $columns, true, null, self::bytes($specs, $columns));
                }
            }
        }
        foreach ([...array_map(static fn (string $field): array => [$field], $def->indexedFields()), ...$def->indexes] as $columns) {
            $freeText = self::freeText($specs, $columns);
            $indexes[] = new RecordIndex(
                $grammar->indexName('idx', $def->table, implode('_', $columns)),
                $columns,
                false,
                $freeText,
                $freeText === null ? self::bytes($specs, $columns) : 0,
            );
        }

        return $indexes;
    }

    /**
     * Whether the table has the index already: by its name, or an index (one made by hand, under
     * another name) over the same columns in the same order, for a unique one only a unique one.
     * Public: the conversion of existing tables skips the same ones.
     *
     * @param array<string, list<string>> $existing index name => its columns (SchemaGrammar::indexColumns())
     * @param list<string> $uniques the names of the unique ones (SchemaGrammar::uniqueIndexes())
     */
    public static function found(RecordIndex $index, array $existing, array $uniques): bool
    {
        if (isset($existing[$index->name])) {
            return true;
        }
        foreach ($existing as $name => $columns) {
            if ($columns === $index->columns && (!$index->unique || in_array($name, $uniques, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many values (over all the columns, none of them empty) more than one row holds.
     *
     * @param list<string> $columns
     */
    private static function duplicates(\PDO $pdo, SchemaGrammar $grammar, string $table, array $columns): int
    {
        $quoted = array_map($grammar->quote(...), $columns);
        $present = implode(' AND ', array_map(static fn (string $c): string => "{$c} IS NOT NULL", $quoted));
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $grammar->quote($table) . " WHERE {$present} GROUP BY "
            . implode(', ', $quoted) . ' HAVING COUNT(*) > 1) AS held';

        return (int) $pdo->query($sql)->fetchColumn();
    }

    /**
     * The most bytes a key over the columns takes on MySQL (utf8mb4): four a character of a short
     * text, eight a number, date or yes/no.
     *
     * @param array<string, ColumnSpec> $specs
     * @param list<string> $columns none of them free text
     */
    private static function bytes(array $specs, array $columns): int
    {
        $bytes = 0;
        foreach ($columns as $column) {
            $spec = $specs[$column];
            $bytes += $spec->kind === ColumnKind::Short ? ($spec->length ?? FieldKinds::DEFAULT_LENGTH) * 4 : 8;
        }

        return $bytes;
    }

    /**
     * The declared lengths of the short-text columns that have one, for a prefix index over a TEXT column.
     *
     * @param array<string, ColumnSpec> $specs
     * @return array<string, int>
     */
    private static function lengths(array $specs): array
    {
        $lengths = [];
        foreach ($specs as $name => $spec) {
            if ($spec->kind === ColumnKind::Short && $spec->length !== null) {
                $lengths[$name] = $spec->length;
            }
        }

        return $lengths;
    }

    /**
     * The first of the columns that can't carry an index (free text, or not a column), or null.
     *
     * @param array<string, ColumnSpec> $specs
     * @param list<string> $columns
     */
    private static function freeText(array $specs, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (!isset($specs[$column]) || $specs[$column]->kind === ColumnKind::Text) {
                return $column;
            }
        }

        return null;
    }

    /**
     * A type as the server reports it: lower case, without its character set and collation
     * (MySQL's COLUMN_TYPE and SQLite's table_info have neither). A column's collation is not
     * drift here: migrate converts it (the data README, "Exact comparisons").
     */
    private static function reported(string $type): string
    {
        return strtolower(trim((string) preg_replace('/\s+(?:COLLATE|CHARACTER\s+SET|CHARSET)\s+\S+/i', '', $type)));
    }
}
