<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\ColumnSpec;

/** The DDL text here is pinned by SqliteDdlCharacterisationTest: existing sites must not see changes. */
final class SqliteGrammar implements SchemaGrammar
{
    use RecordIndexNames;

    /** @param (\Closure(\PDO): string)|null $version reports the server's SQLite version (default: sqlite_version()); injectable for tests */
    public function __construct(private readonly ?\Closure $version = null)
    {
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function columnType(string $kind, ?int $length = null): string
    {
        return match ($kind) {
            'integer', 'boolean' => 'INTEGER',
            default => 'TEXT',
        };
    }

    public function createTable(string $table, array $columns): string
    {
        $parts = [];

        foreach ($columns as $col) {
            $def = $this->quote($col['name']) . ' ' . $this->columnType($col['kind'], $col['length']);

            if ($col['primary']) {
                $def .= ' PRIMARY KEY';
            }

            if (!$col['nullable'] && !$col['primary']) {
                $def .= ' NOT NULL';
            }

            $parts[] = $def;
        }

        return 'CREATE TABLE ' . $this->quote($table) . " (\n    " . implode(",\n    ", $parts) . "\n)";
    }

    public function createRecordTable(string $table, string $idField, array $columns): string
    {
        $defs = array_map(fn (ColumnSpec $c): string => $this->quote($c->name) . ' ' . $this->recordColumnType($c->kind, $c->length), $columns);

        return 'CREATE TABLE ' . $this->quote($table) . ' (' . $this->quote($idField) . ' TEXT PRIMARY KEY, '
            . implode(', ', $defs) . ')';
    }

    /**
     * NUMERIC, not REAL: '12' stays the integer 12 (REAL would read back 12.0), '12.5' becomes 12.5,
     * and numbers sort numerically. SQLite has no bounded strings: Short is TEXT.
     */
    public function recordColumnType(ColumnKind $kind, ?int $length = null): string
    {
        return match ($kind) {
            ColumnKind::Number => 'NUMERIC',
            ColumnKind::Boolean => 'INTEGER',
            ColumnKind::Text, ColumnKind::Short, ColumnKind::Date => 'TEXT',
        };
    }

    public function createRecordIndex(string $name, string $table, array $columns, bool $unique, array $columnTypes = [], array $lengths = []): string
    {
        return 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table)
            . ' (' . implode(', ', array_map($this->quote(...), $columns)) . ')';
    }

    public function createIndex(string $table, string $column): string
    {
        return 'CREATE INDEX ' . $this->quote("idx_{$table}_{$column}")
            . ' ON ' . $this->quote($table) . ' (' . $this->quote($column) . ')';
    }

    public function dropTable(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    public function hasTable(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function columns(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function columnTypes(\PDO $pdo, string $table): array
    {
        $types = [];
        foreach ($pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $types[(string) $row['name']] = strtolower((string) $row['type']);
        }

        return $types;
    }

    public function indexes(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $row): string => (string) $row['name'], $rows);
    }

    public function uniqueIndexes(\PDO $pdo, string $table): array
    {
        $rows = $pdo->query('PRAGMA index_list(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $row): string => (string) $row['name'], array_filter($rows, static fn (array $row): bool => (int) $row['unique'] === 1)));
    }

    public function indexColumns(\PDO $pdo, string $table): array
    {
        $result = [];
        foreach ($this->indexes($pdo, $table) as $name) {
            $rows = $pdo->query('PRAGMA index_info(' . $this->quote($name) . ')')->fetchAll(\PDO::FETCH_ASSOC);
            $result[$name] = array_map(static fn (array $row): string => (string) $row['name'], $rows);
        }

        return $result;
    }

    public function dropIndex(string $table, string $index): string
    {
        if (str_starts_with(strtolower($index), 'sqlite_autoindex_')) {
            throw new \LogicException("Cannot drop the index \"{$index}\": SQLite creates it for a PRIMARY KEY or UNIQUE constraint of the table and cannot drop it.");
        }

        return 'DROP INDEX ' . $this->quote($index);
    }

    public function assertCanDropColumn(\PDO $pdo): void
    {
        $version = $this->version !== null ? ($this->version)($pdo) : (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
        if (version_compare($version, '3.35.0', '<')) {
            throw new \LogicException("dropColumn needs SQLite 3.35 or newer; this server has {$version}.");
        }
    }

    public function addColumn(string $table, string $column, ?string $type = null): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->quote($column) . ' ' . ($type ?? 'TEXT');
    }

    public function renameColumn(string $table, string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' RENAME COLUMN ' . $this->quote($from) . ' TO ' . $this->quote($to);
    }

    public function copyColumn(string $table, string $from, string $to): string
    {
        return 'UPDATE ' . $this->quote($table) . ' SET ' . $this->quote($to) . ' = ' . $this->quote($from) . ' WHERE ' . $this->quote($to) . ' IS NULL';
    }

    public function dropColumn(string $table, string $column): string
    {
        return 'ALTER TABLE ' . $this->quote($table) . ' DROP COLUMN ' . $this->quote($column);
    }

    /**
     * SQLite cannot change a column, so the table is rebuilt (sqlite.org/lang_altertable.html, "other
     * kinds of table schema changes"): a new table with every column as PRAGMA table_info reports it
     * (type, NOT NULL, DEFAULT, primary key) and $column changed, the rows copied, the old table dropped,
     * the new one renamed, its indexes and triggers made again; views keep naming the table. All in one
     * savepoint, so it joins a transaction the caller has open. Foreign keys are switched off for the
     * rebuild (and checked before it commits) when no transaction is open, as dropping the old table
     * would otherwise act on the rows that refer to it; with one open they must already be off.
     * Refuses (LogicException, nothing changed) a table whose CREATE statement has what table_info
     * cannot carry over: REFERENCES, CHECK, UNIQUE, COLLATE, CONSTRAINT, AUTOINCREMENT, a generated
     * column, WITHOUT ROWID or STRICT.
     */
    public function convertColumn(\PDO $pdo, string $table, string $column, string $type, ?bool $nullable): void
    {
        $find = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $find->execute([$table]);
        $create = (string) $find->fetchColumn();
        $find = null; // an open statement would lock the table against the DROP below
        // Identifiers and literals out of the way, so a column named "check" is no constraint.
        $bare = (string) preg_replace('/"(?:[^"]|"")*"|\'(?:[^\']|\'\')*\'|`(?:[^`]|``)*`|\[[^\]]*\]/', '', $create);
        if (preg_match('/\b(REFERENCES|CHECK|UNIQUE|COLLATE|CONSTRAINT|AUTOINCREMENT|GENERATED|WITHOUT\s+ROWID|STRICT)\b|\bAS\s*\(/i', $bare, $m) === 1) {
            throw new \LogicException("Cannot convert \"{$table}.{$column}\" on SQLite: the table is rebuilt for it, and its definition has " . strtoupper($m[1] ?? 'AS (') . ', which the rebuild cannot keep.');
        }
        $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
        if ($foreignKeys && $pdo->inTransaction()) {
            throw new \LogicException("Cannot convert \"{$table}.{$column}\" on SQLite inside a transaction while foreign keys are on: SQLite cannot switch them off there.");
        }

        $info = $pdo->query('PRAGMA table_info(' . $this->quote($table) . ')')->fetchAll(\PDO::FETCH_ASSOC);
        $keys = array_values(array_filter($info, static fn (array $c): bool => (int) $c['pk'] > 0));
        usort($keys, static fn (array $a, array $b): int => (int) $a['pk'] <=> (int) $b['pk']);
        $defs = [];
        foreach ($info as $col) {
            $own = strcasecmp((string) $col['name'], $column) === 0;
            $def = trim($this->quote((string) $col['name']) . ' ' . ($own ? $type : (string) $col['type']));
            if (count($keys) === 1 && (int) $col['pk'] === 1) {
                $def .= ' PRIMARY KEY';
            }
            if ($own ? ($nullable ?? (int) $col['notnull'] === 0) === false : (int) $col['notnull'] === 1) {
                $def .= ' NOT NULL';
            }
            if ($col['dflt_value'] !== null) {
                $def .= ' DEFAULT ' . $col['dflt_value'];
            }
            $defs[] = $def;
        }
        if (count($keys) > 1) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', array_map(fn (array $c): string => $this->quote((string) $c['name']), $keys)) . ')';
        }
        $kept = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND tbl_name = ? AND sql IS NOT NULL ORDER BY type");
        $kept->execute([$table]);
        $remake = $kept->fetchAll(\PDO::FETCH_COLUMN);
        $kept = null;
        $new = $table . '__preflow_converting';
        $names = implode(', ', array_map(fn (array $c): string => $this->quote((string) $c['name']), $info));

        if ($foreignKeys) {
            $pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $legacy = (int) $pdo->query('PRAGMA legacy_alter_table')->fetchColumn();
        $pdo->exec('SAVEPOINT preflow_convert_column');
        try {
            $pdo->exec('CREATE TABLE ' . $this->quote($new) . ' (' . implode(', ', $defs) . ')');
            $pdo->exec('INSERT INTO ' . $this->quote($new) . " ({$names}) SELECT {$names} FROM " . $this->quote($table));
            $pdo->exec('DROP TABLE ' . $this->quote($table));
            // Legacy rename: views and triggers elsewhere that name the table are left as they are (they
            // name it again once the rename is done) instead of being checked against the missing table.
            $pdo->exec('PRAGMA legacy_alter_table = ON');
            $pdo->exec('ALTER TABLE ' . $this->quote($new) . ' RENAME TO ' . $this->quote($table));
            $pdo->exec('PRAGMA legacy_alter_table = ' . $legacy);
            foreach ($remake as $sql) {
                $pdo->exec((string) $sql);
            }
            if ($foreignKeys && $pdo->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new \LogicException("Cannot convert \"{$table}.{$column}\": the rebuilt table breaks a foreign key.");
            }
            $pdo->exec('RELEASE preflow_convert_column');
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO preflow_convert_column');
            $pdo->exec('RELEASE preflow_convert_column');
            $pdo->exec('PRAGMA legacy_alter_table = ' . $legacy);
            throw $e;
        } finally {
            if ($foreignKeys) {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }

    public function createMigrationsTable(): string
    {
        return 'CREATE TABLE IF NOT EXISTS preflow_migrations (
            migration TEXT PRIMARY KEY,
            ran_at TEXT NOT NULL
        )';
    }

    public function canIndex(string $kind): bool
    {
        return true;
    }
}
