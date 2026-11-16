<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Dumps;
use Preflow\Data\Maintenance;

final class SqliteDriver extends PdoDriver implements Maintenance, Dumps
{
    /** @param \PDO|\Closure(): \PDO $pdo A connection, or a closure that opens it on the first query. */
    public function __construct(\PDO|\Closure $pdo, ?\Preflow\Core\Debug\DebugCollector $collector = null)
    {
        $dialect = new SqliteDialect();
        parent::__construct($pdo, $dialect, new QueryCompiler($dialect), $collector);
    }

    /** As PdoDriver's, and the Unicode ulike() for LIKE that ignores case in every alphabet (SqliteDialect::prepare()). */
    protected function configure(\PDO $pdo): void
    {
        parent::configure($pdo);
        SqliteDialect::prepare($pdo);
    }

    /** Whether exclusive() has its BEGIN IMMEDIATE open (PDO before PHP 8.5 does not report it). */
    private bool $immediate = false;

    /**
     * Inside BEGIN IMMEDIATE: the database's write lock, taken up front, so no other connection
     * writes until $fn is done and committed. A caller's open transaction is used as it is.
     * The transaction is tracked here, not by PDO::inTransaction(), which reports one opened
     * with exec() only from PHP 8.5.
     */
    public function exclusive(string $type, \Closure $fn): mixed
    {
        if ($this->inTransaction()) {
            return $fn();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->immediate = true;
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->immediate = false;
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
                // Already rolled back by SQLite; the original failure is what matters.
            }
            throw $e;
        }
        $this->immediate = false;
        try {
            $this->pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\PDOException) {
            }
            throw $e;
        }

        return $result;
    }

    /** PDO sends false as '', which an INTEGER column keeps as text: bools go as 1/0, as on MySQL. */
    protected function prepareBindings(array $bindings): array
    {
        return array_map(static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v, $bindings);
    }

    public function inTransaction(): bool
    {
        return $this->immediate || parent::inTransaction();
    }

    /**
     * PRAGMA integrity_check and PRAGMA foreign_key_check on the prepared connection (the
     * sqlite3 tool cannot run them on tables with the preflow_text collation).
     *
     * @since 0.78
     */
    public function check(): array
    {
        $problems = [];
        foreach ($this->pdo->query('PRAGMA integrity_check')->fetchAll(\PDO::FETCH_COLUMN) as $line) {
            if ($line !== 'ok') {
                $problems[] = (string) $line;
            }
        }
        foreach ($this->pdo->query('PRAGMA foreign_key_check')->fetchAll(\PDO::FETCH_NUM) as $row) {
            [$table, $rowid, $parent] = $row;
            $problems[] = "{$table}: row {$rowid} refers to a missing row of {$parent}";
        }

        return $problems;
    }

    /** VACUUM on the prepared connection. @since 0.78 */
    public function compact(): void
    {
        if ($this->inTransaction()) {
            throw new \LogicException('compact() cannot run inside a transaction.');
        }
        $this->pdo->exec('VACUUM');
    }

    /**
     * SQL text as the sqlite3 tool's .dump writes it (CREATE TABLE, INSERT INTO … VALUES with
     * SQLite's quote(), sqlite_sequence, then indexes, triggers and views, in one transaction),
     * read inside one read transaction. A virtual table (FTS) is refused: its shadow tables
     * cannot be restored as plain tables.
     *
     * @since 0.78
     */
    public function dump(mixed $out): void
    {
        $write = static function (string $text) use ($out): void {
            if (fwrite($out, $text) !== strlen($text)) {
                throw new \RuntimeException('Could not write the dump.');
            }
        };
        $this->pdo->exec('BEGIN');
        try {
            $schema = $this->pdo->query("SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY rowid")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($schema as $entry) {
                if ($entry['type'] === 'table' && preg_match('/^\s*CREATE\s+VIRTUAL\s+TABLE/i', (string) $entry['sql']) === 1) {
                    throw new \RuntimeException("The virtual table {$entry['name']} cannot be dumped; nothing was written.");
                }
            }
            $write("-- Preflow SQLite dump. Restore it with `preflow db:restore` (the sqlite3 tool cannot: text columns use the preflow_text collation).\n");
            $write("PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
            $sequence = false;
            foreach ($schema as $entry) {
                $name = (string) $entry['name'];
                if ($entry['type'] !== 'table') {
                    continue;
                }
                if ($name === 'sqlite_sequence') {
                    $sequence = true;
                    continue;
                }
                if (str_starts_with($name, 'sqlite_')) {
                    continue;
                }
                $write($entry['sql'] . ";\n");
                $table = $this->dialect->quoteIdentifier($name);
                $columns = $this->pdo->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_COLUMN, 1);
                $values = implode(" || ',' || ", array_map(fn (string $c): string => 'quote(' . $this->dialect->quoteIdentifier($c) . ')', $columns));
                $prefix = 'INSERT INTO ' . $table . ' VALUES(';
                foreach ($this->pdo->query("SELECT {$values} FROM {$table}") ?: [] as $row) {
                    $write($prefix . $row[0] . ");\n");
                }
            }
            if ($sequence) {
                $write("DELETE FROM sqlite_sequence;\n");
                foreach ($this->pdo->query("SELECT quote(name) || ',' || quote(seq) FROM sqlite_sequence") ?: [] as $row) {
                    $write('INSERT INTO sqlite_sequence VALUES(' . $row[0] . ");\n");
                }
            }
            foreach ($schema as $entry) {
                if ($entry['type'] !== 'table') {
                    $write($entry['sql'] . ";\n");
                }
            }
            $write("COMMIT;\n");
        } finally {
            $this->pdo->exec('COMMIT');
        }
    }

    /**
     * Runs a dump() on the prepared connection, so its preflow_text columns and indexes are made
     * and filled; the database must hold no tables. The whole dump is read into memory.
     *
     * @since 0.78
     */
    public function restore(mixed $in): void
    {
        $tables = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(\PDO::FETCH_COLUMN);
        if ($tables !== []) {
            throw new \RuntimeException('A dump is restored into an empty database only; this one holds ' . implode(', ', $tables) . '.');
        }
        $sql = stream_get_contents($in);
        if ($sql === false) {
            throw new \RuntimeException('Could not read the dump.');
        }
        // The restore is one transaction of its own, whatever the text says: the dump's own header
        // (foreign keys off, BEGIN) and trailer (COMMIT) are taken off, so a dump without them (a
        // hand-edited one, or the sqlite3 shell's) is all-or-nothing too. Foreign keys cannot change
        // inside a transaction, so they go off before it and back to what they were after it.
        $sql = (string) preg_replace(['/\A((?:\s*--[^\n]*(?:\n|\z))*)\s*(?:PRAGMA\s+foreign_keys\s*=\s*OFF\s*;\s*)?(?:BEGIN(?:\s+TRANSACTION)?\s*;\s*)?/i', '/\s*COMMIT(?:\s+TRANSACTION)?\s*;\s*\z/i'], ['$1', ''], $sql);
        $foreignKeys = $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $this->pdo->exec('PRAGMA foreign_keys=OFF');
        try {
            $this->pdo->exec('BEGIN');
            try {
                $this->pdo->exec($sql);
                $this->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\PDOException) {
                    // SQLite already rolled back.
                }
                throw $e;
            }
        } finally {
            $this->pdo->exec('PRAGMA foreign_keys=' . ((int) $foreignKeys === 1 ? 'ON' : 'OFF'));
        }
    }
}
