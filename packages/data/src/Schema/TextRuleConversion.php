<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\DataManager;
use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;
use Preflow\Data\Migration\Grammar\TextCollations;
use Preflow\Data\Migration\MigrationColumns;
use Preflow\Data\PdoConnection;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\UniqueIndexes;

/**
 * What `preflow migrate` does for the text rule (ignore case, respect accents; the data README's
 * "Existing tables (`preflow migrate`)") besides the record tables' own columns, which their sync
 * steps convert (RecordTableSync::collations()):
 *
 * - convert() (the step `data.text-rule`):
 *   - On the storage the migrations run on (MigrationRegistry::ranOn()): the tables whose columns'
 *     intent is known get the collation of their intent, text the rule's and exact the exact one
 *     (CollationChange: one statement per table, unique indexes counted first, duplicates listed
 *     and left as they are). Their intent comes from DeclaredTables (the framework's tables, or
 *     an app's) and from the migration bookkeeping (MigrationColumns: tables a migration made
 *     since 0.78). Every other table there with text columns and no known intent (one a site's
 *     migration made before 0.78, or another application's) is left as it is and listed once,
 *     with the way to adopt it. A table on another storage is converted only when declared for it.
 *     Record tables (RecordTables) and tables in config/data.php 'text_rule' => ['skip' => [...]]
 *     are not touched here.
 *   - On every SQL storage where the application supplies the collation (SQLite's preflow_text,
 *     TextCollations::textCollationVersion()): the indexes over text columns are rebuilt when the
 *     rule's version is not the one recorded in the table preflow_text_collation (or none is). A
 *     unique index whose values are now equal is left as it was (and stays in use), its groups are
 *     listed ("refused:") and the version is not recorded, so the next migrate tries again.
 *   - A server without the rule's collation (MariaDB before 10.10, MySQL before 8.0) is reported
 *     once; its columns keep their comparison.
 * - duplicates() (the step `data.text-duplicates`, any storage): the values of unique text fields
 *   no unique index holds (every unique field on storage without UniqueIndexes, such as JSON; a
 *   translatable one anywhere, per locale) that are there more than once under the rule. Listed as
 *   "refused:" lines, since saving one of those records is refused until they differ.
 * - check() (`migrate --pretend`): every "refused:" line the steps would print, changing nothing.
 *
 * Every refusal starts its line with "refused:", which makes `preflow migrate` exit 1. Nothing in
 * a row is ever changed or removed.
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
     * @param string|null $migrated the storage the migrations run on (MigrationRegistry::storage()); null: none in this run
     * @param list<string> $skip tables never converted (config/data.php text_rule.skip)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly ?RecordTables $records = null,
        private readonly ?DeclaredTables $declared = null,
        private readonly ?string $migrated = null,
        private readonly array $skip = [],
    ) {}

    /** @return list<string> the changes made, warnings and refusals, one line each */
    public function convert(): array
    {
        return $this->run(false);
    }

    /** @return list<string> the "refused:" lines convert(), duplicates() and the record tables' syncs would print; nothing changed */
    public function check(): array
    {
        $lines = [];
        foreach ($this->records?->all() ?? [] as $entry) {
            array_push($lines, ...(new RecordTableSync($this->dm))->collations($entry['def'], $entry['extra'], $entry['typed'], dryRun: true));
        }
        array_push($lines, ...$this->run(true), ...$this->duplicates());

        return array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'refused:') || str_starts_with($line, '  ')));
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

    /** @return list<string> */
    private function run(bool $dryRun): array
    {
        $lines = [];
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
            if (!$dryRun && $grammar->textCollationVersion() !== null) {
                array_push($lines, ...$this->reindex($pdo, $grammar));
            }
            array_push($lines, ...$this->intents($pdo, $grammar, $driver, $dryRun));
        }

        return $lines;
    }

    /**
     * The declared and recorded tables of this storage converted, and on the migrated storage the
     * tables without known intent listed once.
     *
     * @return list<string>
     */
    private function intents(\PDO $pdo, SchemaGrammar&TextCollations $grammar, PdoConnection $driver, bool $dryRun): array
    {
        $migrated = $this->migrated !== null && $this->dm->driver($this->migrated) === $driver;
        $records = array_map(static fn (array $entry): string => $entry['def']->table, $this->entries($driver));
        $bookkeeping = new MigrationColumns($pdo);

        /** @var array<string, array<string, string>> $intents table => column => intent */
        $intents = $migrated ? $bookkeeping->tables() : [];
        foreach ($this->declared?->all() ?? [] as $declared) {
            $here = $declared['storage'] === null ? $migrated : $this->dm->driver($declared['storage']) === $driver;
            if ($here) {
                $intents[$declared['table']] = [...($intents[$declared['table']] ?? []), ...$declared['columns']];
            }
        }
        ksort($intents, SORT_STRING);
        $existing = $grammar->tables($pdo);

        $lines = [];
        foreach ($intents as $table => $columns) {
            $table = (string) $table;
            if (in_array($table, $this->skip, true) || in_array($table, $records, true) || !in_array($table, $existing, true)) {
                continue;
            }
            array_push($lines, ...$this->toIntent($pdo, $grammar, $table, $columns, $dryRun));
        }
        if (!$migrated || $dryRun || !$bookkeeping->exists()) {
            return $lines;
        }

        // Tables with text columns and no known intent: listed once (made before 0.78, or not by Preflow).
        $known = [...array_keys($intents), ...$records, ...$this->skip, ...$bookkeeping->listed(), self::VERSION_TABLE, MigrationColumns::TABLE];
        foreach (array_diff($existing, $known) as $table) {
            $text = array_filter($grammar->columnCollations($pdo, $table), static fn (?string $c): bool => $c !== null && !$grammar->isTextCollation($c));
            if ($text === []) {
                continue;
            }
            $lines[] = "warning: {$table} has no recorded text rule (made by a migration before 0.78, or not by Preflow): its columns "
                . implode(', ', array_keys($text)) . ' keep their comparison. Adopt it with a migration (Schema::convertColumn(), see the data README) or list it in text_rule.skip';
            $bookkeeping->markListed($table);
        }

        return $lines;
    }

    /**
     * @param array<string, string> $columns column => MigrationColumns::TEXT or ::EXACT
     * @return list<string>
     */
    private function toIntent(\PDO $pdo, SchemaGrammar&TextCollations $grammar, string $table, array $columns, bool $dryRun): array
    {
        $current = $grammar->columnCollations($pdo, $table);
        $types = $grammar->columnTypes($pdo, $table);
        $lines = [];
        $changes = [];
        foreach ($columns as $column => $intent) {
            $column = (string) $column;
            $collation = $current[$column] ?? null;
            if ($collation === null) {
                continue; // not there, or holds no text
            }
            $text = $intent === MigrationColumns::TEXT;
            if ($text ? $grammar->isTextCollation($collation) : $grammar->isExactCollation($collation)) {
                continue;
            }
            $refusal = $grammar->collationRefusal($types[$column], $collation);
            if ($refusal !== null) {
                $lines[] = "warning: {$table}.{$column} is {$refusal}; it keeps its collation";
                continue;
            }
            $changes[$column] = ['type' => $grammar->withCollation($types[$column], $text), 'text' => $text];
        }

        return [...$lines, ...CollationChange::apply($pdo, $grammar, $table, null, $changes, $dryRun)];
    }

    /**
     * Rebuilds the indexes over text columns when the rule's version differs from the recorded
     * one, or none is recorded.
     *
     * @return list<string>
     */
    private function reindex(\PDO $pdo, SchemaGrammar&TextCollations $grammar): array
    {
        $version = (string) $grammar->textCollationVersion();
        $quoted = $grammar->quote(self::VERSION_TABLE);
        if (!$grammar->hasTable($pdo, self::VERSION_TABLE)) {
            $pdo->exec($grammar->createTable(self::VERSION_TABLE, [['name' => 'icu', 'kind' => 'string', 'length' => 191, 'nullable' => false, 'primary' => false, 'exact' => true]]));
        }
        $statement = $pdo->query("SELECT icu FROM {$quoted}");
        $recorded = $statement->fetchColumn();
        $statement->closeCursor();
        $recorded = $recorded === false ? null : (string) $recorded;
        if ($recorded === $version) {
            return [];
        }

        $lines = [];
        foreach ($grammar->reindexText($pdo) as $failed) {
            // The index was built under another version: the rows are grouped without it.
            $groups = TextDuplicates::scan($pdo, $grammar, $failed['table'], $this->idField($failed['table']), $failed['columns']);
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
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->exec("DELETE FROM {$quoted}");
            $pdo->prepare("INSERT INTO {$quoted} (icu) VALUES (?)")->execute([$version]);
            if ($own) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $recorded === null ? [] : ["rebuilt the indexes over text columns for {$version} (was {$recorded})"];
    }

    /** The id column of a record table; null for another table (TextCollations::rowKey()). */
    private function idField(string $table): ?string
    {
        foreach ($this->records?->all() ?? [] as $entry) {
            if ($entry['def']->table === $table) {
                return $entry['def']->idField;
            }
        }

        return null;
    }

    /**
     * The record tables stored through this driver.
     *
     * @return list<Entry>
     */
    private function entries(PdoConnection $driver): array
    {
        return array_values(array_filter(
            $this->records?->all() ?? [],
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
        foreach ($this->records?->all() ?? [] as $entry) {
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
