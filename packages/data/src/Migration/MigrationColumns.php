<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

use Preflow\Data\Migration\Grammar\Grammars;
use Preflow\Data\Migration\Grammar\SchemaGrammar;

/**
 * Preflow's migration bookkeeping of columns (since 0.78): for each table a migration made
 * through Schema under Migrator, each column's intent, text (compared by the text rule: a
 * string() or text() not marked exact()) or exact (every other column). Kept in the table
 * preflow_migration_columns of the database the migrations ran on, beside preflow_migrations.
 * `preflow migrate` gives those columns the collation of their intent on tables made before
 * (Schema\TextRuleConversion), so a table a migration made converts the same on every storage
 * without running any migration again. A table made before 0.78 has no recorded columns: it is
 * left as it is until a migration adopts it (Schema::convertColumn() records the column it
 * converts). The table also notes the tables migrate has listed as having no recorded intent,
 * so it lists each once.
 *
 * @since 0.78
 */
final class MigrationColumns
{
    public const string TABLE = 'preflow_migration_columns';

    public const string TEXT = 'text';
    public const string EXACT = 'exact';

    /** The intent of a table's row that notes migrate listed it (column ''). */
    private const string LISTED = 'listed';

    private readonly SchemaGrammar $grammar;

    private bool $ready = false;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->grammar = Grammars::for($pdo);
    }

    /** Whether the database has the bookkeeping table (a database Migrator has run on since 0.78). */
    public function exists(): bool
    {
        return $this->ready || $this->grammar->hasTable($this->pdo, self::TABLE);
    }

    /** Records a column's intent (TEXT or EXACT), replacing what was recorded for it. */
    public function record(string $table, string $column, string $intent): void
    {
        if (!in_array($intent, [self::TEXT, self::EXACT], true)) {
            throw new \InvalidArgumentException("A column's intent is \"text\" or \"exact\", not \"{$intent}\".");
        }
        $this->write($table, $column, $intent);
    }

    /** Forgets a dropped table, its columns and its listing. */
    public function forgetTable(string $table): void
    {
        $this->ensure();
        $this->pdo->prepare('DELETE FROM ' . $this->grammar->quote(self::TABLE) . ' WHERE table_name = ?')->execute([$table]);
    }

    public function forgetColumn(string $table, string $column): void
    {
        $this->ensure();
        $this->pdo->prepare('DELETE FROM ' . $this->grammar->quote(self::TABLE) . ' WHERE id = ?')->execute([self::id($table, $column)]);
    }

    public function renameColumn(string $table, string $from, string $to): void
    {
        $intent = $this->tables()[$table][$from] ?? null;
        if ($intent !== null) {
            $this->forgetColumn($table, $from);
            $this->write($table, $to, $intent);
        }
    }

    /** @return array<string, array<string, string>> table => column => intent, of the tables with recorded columns */
    public function tables(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $tables = [];
        $rows = $this->pdo->query('SELECT table_name, column_name, intent FROM ' . $this->grammar->quote(self::TABLE) . ' ORDER BY table_name, column_name')->fetchAll(\PDO::FETCH_NUM);
        foreach ($rows as [$table, $column, $intent]) {
            if ($intent !== self::LISTED) {
                $tables[(string) $table][(string) $column] = (string) $intent;
            }
        }

        return $tables;
    }

    /** Notes that migrate listed the table as one without recorded intent. */
    public function markListed(string $table): void
    {
        $this->write($table, '', self::LISTED);
    }

    /** @return list<string> the tables migrate has listed */
    public function listed(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $stmt = $this->pdo->prepare('SELECT table_name FROM ' . $this->grammar->quote(self::TABLE) . ' WHERE intent = ? ORDER BY table_name');
        $stmt->execute([self::LISTED]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Makes the bookkeeping table when missing (Migrator does on every run). */
    public function ensure(): void
    {
        if ($this->ready) {
            return;
        }
        if (!$this->grammar->hasTable($this->pdo, self::TABLE)) {
            $exact = static fn (string $name, bool $primary = false): array => ['name' => $name, 'kind' => 'string', 'length' => 191, 'nullable' => false, 'primary' => $primary, 'exact' => true];
            $this->pdo->exec($this->grammar->createTable(self::TABLE, [$exact('id', true), $exact('table_name'), $exact('column_name'), $exact('intent')]));
        }
        $this->ready = true;
    }

    private function write(string $table, string $column, string $intent): void
    {
        $this->ensure();
        $quoted = $this->grammar->quote(self::TABLE);
        $this->pdo->prepare("DELETE FROM {$quoted} WHERE id = ?")->execute([self::id($table, $column)]);
        $this->pdo->prepare("INSERT INTO {$quoted} (id, table_name, column_name, intent) VALUES (?, ?, ?, ?)")->execute([self::id($table, $column), $table, $column, $intent]);
    }

    private static function id(string $table, string $column): string
    {
        return $table . "\x1F" . $column;
    }
}
