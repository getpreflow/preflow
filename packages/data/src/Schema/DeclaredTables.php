<?php

declare(strict_types=1);

namespace Preflow\Data\Schema;

use Preflow\Data\Migration\MigrationColumns;

/**
 * The tables packages (and an app) make with migrations, declared with each column's intent:
 * text (compared by the text rule) or exact (a machine value: ids, tokens, hashes, purposes).
 * What RecordTables is for the tables of types, this is for the framework's own tables
 * (preflow_throttle, auth_tokens, auth_remember, code_redemption, the migration bookkeeping):
 * `preflow migrate` gives an existing table's columns the collation of their intent
 * (TextRuleConversion), so a table made before 0.78 compares as a new one does, on every storage.
 * A table is converted on the storage its migrations run on, unless its entry names another.
 * Only the columns named are converted; numbers need none.
 *
 *     $tables->add('auth_tokens', ['id' => 'exact', 'purpose' => 'exact', 'subject' => 'exact']);
 *
 * @phpstan-type Declared array{table: string, columns: array<string, string>, storage: ?string}
 * @since 0.78
 */
final class DeclaredTables
{
    /** @var array<string, Declared> */
    private array $tables = [];

    /**
     * @param array<string, string> $columns column => MigrationColumns::TEXT or ::EXACT
     * @param string|null $storage the storage holding it; null: the one `preflow migrate` runs the migrations on
     */
    public function add(string $table, array $columns, ?string $storage = null): void
    {
        if (isset($this->tables[$table])) {
            throw new \RuntimeException("The table [{$table}] is already declared.");
        }
        foreach ($columns as $column => $intent) {
            if (!in_array($intent, [MigrationColumns::TEXT, MigrationColumns::EXACT], true)) {
                throw new \InvalidArgumentException("The column {$table}.{$column} is \"text\" or \"exact\", not \"{$intent}\".");
            }
        }
        $this->tables[$table] = ['table' => $table, 'columns' => $columns, 'storage' => $storage];
    }

    /** @return list<Declared> */
    public function all(): array
    {
        return array_values($this->tables);
    }
}
