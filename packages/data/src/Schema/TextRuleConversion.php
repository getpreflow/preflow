<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Grammar\TextCollations;
use Preflow\Data\Migration\MigrationTables;
use Preflow\Data\PdoConnection;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\UniqueIndexes;

/**
 * What `preflow migrate` does for the text rule (ignore case, respect accents; the data README's
 * "Exact comparisons") besides the record tables' own columns, which RecordTableSync converts:
 *
 * - convert() (the step `data.text-rule`), on each SQL storage whose grammar carries the rule
 *   (TextCollations):
 *   - Preflow's tables keep no column looser than the rule: a column whose collation ignores
 *     accents as well as case (TextCollations::isLooseCollation(), MySQL's utf8mb4_unicode_ci and
 *     the like) gets the rule's, one convertCollations() per table. Stricter, so no two values
 *     can become equal. Preflow's tables are the record tables (RecordTables; of a typed one only
 *     the columns its sync does not type, such as fields a type no longer has), the tables the
 *     migrations make (MigrationTables) and preflow_migrations; never another application's, nor
 *     one in config/data.php 'text_rule' => ['skip' => [...]].
 *   - Where the application supplies the collation (SQLite's preflow_text,
 *     TextCollations::textCollationVersion()), the indexes over text columns are rebuilt when the
 *     rule's version changed since the last run, recorded in the table preflow_text_collation.
 *     A unique index whose values are now equal is left as it was, its duplicates are listed
 *     ("refused:") and the version is not recorded, so the next migrate tries again.
 *   - A server without the rule's collation (MariaDB before 10.10, MySQL before 8.0) is reported
 *     once; its columns keep their comparison.
 * - duplicates() (the step `data.text-duplicates`, any storage): the values of unique text fields
 *   no unique index holds (every unique field on storage without UniqueIndexes, such as JSON; a
 *   translatable one anywhere, per locale) that are there more than once under the rule
 *   ("Müller" and "MÜLLER"): listed as "refused:" lines, since saving one of those records is
 *   refused until they differ. Nothing is changed.
 *
 * Every refusal starts its line with "refused:", which makes `preflow migrate` exit 1.
 *
 * @phpstan-import-type Entry from RecordTables
 * @since 0.78
 */
final class TextRuleConversion
{
    /** The table that records the rule's version the indexes were last built by (SQLite). */
    public const string VERSION_TABLE = 'preflow_text_collation';

    /** The line for a server without the rule's collation. */
    public const string NO_COLLATION = 'warning: this server has no accent-sensitive, case-insensitive collation (MariaDB 10.10+ or MySQL 8.0+); text columns keep their comparison';

    /**
     * @param list<string> $migrationPaths the migration folders (MigrationRegistry::paths()), whose tables are Preflow's
     * @param list<string> $skip tables never converted (config/data.php text_rule.skip)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?RecordTables $tables = null,
        private readonly array $migrationPaths = [],
        private readonly array $skip = [],
    ) {}

    /** @return list<string> the changes made, warnings and refusals, one line each */
    public function convert(): array
    {
        $lines = [];
        $migrationTables = null;
        foreach ($this->sqlStorages() as $driver) {
            $pdo = $driver->pdo();
            $grammar = Grammars::for($pdo);
            if (!$grammar instanceof TextCollations) {
                continue;
            }
            if ($grammar->textCollation() === null) {
                $lines[] = self::NO_COLLATION;
                continue;
            }
            if ($grammar->textCollationVersion() !== null) {
                array_push($lines, ...$this->reindex($pdo, $grammar, $driver));
            }
            if ($migrationTables === null) {
                $migrationTables = new MigrationTables($this->migrationPaths);
                foreach ($migrationTables->failed() as $file => $error) {
                    $lines[] = "warning: could not tell the tables of the migration {$file} ({$error}); they keep their collation";
                }
            }
            array_push($lines, ...$this->loosen($pdo, $grammar, $driver, $migrationTables->tables()));
        }

        return $lines;
    }

    /** @return list<string> */
    public function duplicates(): array
    {
        $lines = [];
        foreach ($this->definitions() as $def) {
            $driver = $this->dm->driver($def->storage);
            $translatable = $def->translatableFields();
            foreach (array_keys($def->uniqueFields()) as $field) {
                $field = (string) $field;
                $perLocale = in_array($field, $translatable, true);
                if ($driver instanceof UniqueIndexes && !$perLocale) {
                    continue; // its unique index holds it (RecordTableSync converts and lists)
                }
                foreach ($perLocale ? $def->locales->available : [null] as $locale) {
                    $groups = TextDuplicates::records($this->dm, $def, $field, $locale);
                    if ($groups === []) {
                        continue;
                    }
                    array_push($lines, ...TextDuplicates::lines(
                        "refused: {$def->table}.{$field}" . ($locale !== null ? " ({$locale})" : '') . ': ' . TextDuplicates::counted(count($groups))
                            . ' there more than once when case is ignored; saving one of these records is refused until they differ',
                        $groups,
                    ));
                }
            }
        }

        return $lines;
    }

    /**
     * Rebuilds the indexes over text columns when the rule's version differs from the recorded
     * one (or none is recorded: the first run on 0.78, quietly).
     *
     * @return list<string>
     */
    private function reindex(\PDO $pdo, SchemaGrammar&TextCollations $grammar, PdoConnection $driver): array
    {
        $version = (string) $grammar->textCollationVersion();
        $quoted = $grammar->quote(self::VERSION_TABLE);
        if (!$grammar->hasTable($pdo, self::VERSION_TABLE)) {
            $pdo->exec($grammar->createTable(self::VERSION_TABLE, [['name' => 'icu', 'kind' => 'string', 'length' => 191, 'nullable' => false, 'primary' => false, 'exact' => true]]));
        }
        $recorded = $pdo->query("SELECT icu FROM {$quoted}")->fetchColumn();
        $recorded = $recorded === false ? null : (string) $recorded;
        if ($recorded === $version) {
            return [];
        }

        $lines = [];
        foreach ($grammar->reindexText($pdo) as $failed) {
            // The index was built under the old version: the rows are grouped without it.
            $groups = TextDuplicates::scan($pdo, $grammar, $failed['table'], $this->idField($pdo, $grammar, $driver, $failed['table']), $failed['columns']);
            if ($groups === []) {
                $lines[] = "refused: {$failed['table']}: the index {$failed['index']} could not be rebuilt ({$failed['error']}); run migrate again";
                continue;
            }
            array_push($lines, ...TextDuplicates::lines(
                "refused: {$failed['table']}.{$failed['columns'][0]}: " . TextDuplicates::counted(count($groups))
                    . ' there more than once when case is ignored; its unique index keeps the order of ' . ($recorded ?? 'an earlier version') . ' until they differ',
                $groups,
            ));
        }
        if ($lines !== []) {
            return $lines;
        }
        $pdo->exec("DELETE FROM {$quoted}");
        $pdo->prepare("INSERT INTO {$quoted} (icu) VALUES (?)")->execute([$version]);

        return $recorded === null ? [] : ["rebuilt the indexes over text columns for {$version} (was {$recorded})"];
    }

    /**
     * Gives the loose columns of Preflow's tables on this storage the rule's collation.
     *
     * @param list<string> $migrationTables
     * @return list<string>
     */
    private function loosen(\PDO $pdo, SchemaGrammar&TextCollations $grammar, PdoConnection $driver, array $migrationTables): array
    {
        $owned = array_fill_keys([...$migrationTables, 'preflow_migrations'], []);
        foreach ($this->entries($driver) as $entry) {
            $def = $entry['def'];
            // A typed table's own columns are its sync's (RecordTableSync): text or exact by the field.
            $typed = $entry['typed'] ? array_keys((new RecordTableSync($this->dm))->specs($def, $entry['extra'], true, $grammar)) : [];
            $owned[$def->table] = [...($owned[$def->table] ?? []), ...($entry['typed'] ? [$def->idField, ...$typed] : [])];
        }
        ksort($owned, SORT_STRING);

        $lines = [];
        foreach ($owned as $table => $synced) {
            $table = (string) $table;
            if (in_array($table, $this->skip, true) || !$grammar->hasTable($pdo, $table)) {
                continue;
            }
            $types = $grammar->columnTypes($pdo, $table);
            $convert = [];
            foreach ($grammar->columnCollations($pdo, $table) as $column => $collation) {
                if (in_array($column, $synced, true) || !$grammar->isLooseCollation($collation)) {
                    continue;
                }
                $type = $types[$column] ?? '';
                if ($type === '' || preg_match('/^(enum|set)\b/i', $type) === 1) {
                    $lines[] = "warning: {$table}.{$column} is {$type} under {$collation}; it keeps its collation";
                    continue;
                }
                $convert[$column] = $type . ' COLLATE ' . $grammar->textCollation();
            }
            if ($convert === []) {
                continue;
            }
            try {
                $grammar->convertCollations($pdo, $table, $convert);
            } catch (\PDOException | \LogicException $e) {
                $lines[] = "refused: {$table}: the server refused the conversion ({$e->getMessage()}); run migrate again";
                continue;
            }
            $lines[] = "converted collations of {$table}: " . implode(', ', array_keys($convert));
        }

        return $lines;
    }

    /** The id column of a record table, else the table's first column. */
    private function idField(\PDO $pdo, SchemaGrammar $grammar, PdoConnection $driver, string $table): string
    {
        foreach ($this->entries($driver) as $entry) {
            if ($entry['def']->table === $table) {
                return $entry['def']->idField;
            }
        }

        return $grammar->columns($pdo, $table)[0] ?? 'rowid';
    }

    /**
     * The record tables stored through this driver.
     *
     * @return list<Entry>
     */
    private function entries(PdoConnection $driver): array
    {
        return array_values(array_filter(
            $this->tables?->all() ?? [],
            fn (array $entry): bool => $this->dm->driver($entry['def']->storage) === $driver,
        ));
    }

    /**
     * The definitions with unique fields: the registry's types and the typed record tables that
     * hold unique fields, each table once per storage.
     *
     * @return list<TypeDefinition>
     */
    private function definitions(): array
    {
        $defs = [];
        $registry = $this->dm->getTypeRegistry();
        foreach ($registry?->keys() ?? [] as $key) {
            $defs[] = $registry->get($key);
        }
        foreach ($this->tables?->all() ?? [] as $entry) {
            if ($entry['typed'] && $entry['unique']) {
                $defs[] = $entry['def'];
            }
        }
        $seen = [];
        $unique = [];
        foreach ($defs as $def) {
            if ($def->uniqueFields() === []) {
                continue;
            }
            $key = spl_object_id($this->dm->driver($def->storage)) . "\0" . $def->table;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $def;
            }
        }

        return $unique;
    }

    /** @return list<StorageDriver&PdoConnection> each SQL storage once (aliases of one count once) */
    private function sqlStorages(): array
    {
        $found = [];
        foreach ($this->dm->driverNames() as $name) {
            $driver = $this->dm->driver($name);
            if ($driver instanceof PdoConnection && !in_array($driver, $found, true)) {
                $found[] = $driver;
            }
        }

        return $found;
    }
}
