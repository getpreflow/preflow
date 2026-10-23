<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

abstract class PdoDriver implements StorageDriver
{
    public function __construct(
        protected readonly \PDO $pdo,
        protected readonly Dialect $dialect,
        protected readonly QueryCompiler $compiler,
        protected readonly ?\Preflow\Core\Debug\DebugCollector $collector = null,
    ) {}

    /** The connection this driver runs on (the migrator needs it). */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** The SQL dialect of this connection (callers writing their own SQL branch on it). */
    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    /**
     * Values as this connection's execute() binds them (all as strings). The
     * default leaves them alone, so SQLite stores exactly what it did before.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int|string, mixed>
     */
    protected function prepareBindings(array $bindings): array
    {
        return $bindings;
    }

    protected function executeWithLogging(\PDOStatement $stmt, string $sql, array $bindings = []): void
    {
        $start = hrtime(true);
        $stmt->execute($this->prepareBindings($bindings));
        if ($this->collector !== null) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->collector->logQuery($sql, $bindings, $durationMs);
        }
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT * FROM {$table} WHERE {$col} = ? LIMIT 1";
        $stmt = $this->read($type, $sql, [$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        // Get total count (without limit/offset)
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->read($type, $countSql, $countBindings);
        $total = (int) $countStmt->fetchColumn();

        // Get items
        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->read($type, $sql, $bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($items, $total);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        // Structured values (e.g. translatable locale maps) are stored as JSON text.
        $data = array_map(
            static fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $v,
            $data,
        );

        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        if ($isEmpty) {
            unset($data[$idField]);
            $data = array_filter($data, fn ($v) => $v !== null);
            $columns = array_keys($data);
            $sql = $this->dialect->insertSql($type, $columns);
            $this->write($type, $sql, array_values($data));
        } else {
            if (!isset($data[$idField])) {
                $data[$idField] = $id;
            }

            // Filter out null values
            $data = array_filter($data, fn ($v) => $v !== null);

            $columns = array_keys($data);
            $sql = $this->dialect->upsertSql($type, $columns, $idField);
            $this->write($type, $sql, array_values($data));
        }
    }

    /**
     * Runs a write; a record key the table has no column for (a model field
     * added without migrating) names the fix instead of the raw PDO error.
     *
     * @param list<mixed> $bindings
     */
    private function write(string $table, string $sql, array $bindings): void
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, $bindings);
        } catch (\PDOException $e) {
            if (preg_match('/(?:no such column:|has no column named)\s*[\'"`]?([A-Za-z0-9_]+)/', $e->getMessage(), $m) === 1) {
                throw new \RuntimeException("Table {$table} has no column {$m[1]}; run `preflow migrate`.", 0, $e);
            }
            throw $e;
        }
    }

    /**
     * Runs a read; a missing table (a type never migrated) names the fix
     * instead of the raw PDO error.
     *
     * @param list<mixed> $bindings
     */
    private function read(string $table, string $sql, array $bindings): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, $bindings);

            return $stmt;
        } catch (\PDOException $e) {
            if (preg_match('/no such table|base table or view not found|relation .* does not exist/i', $e->getMessage()) === 1) {
                throw new \RuntimeException("Table {$table} does not exist; run `preflow migrate`.", 0, $e);
            }
            throw $e;
        }
    }

    public function lastInsertId(): string|int
    {
        $id = $this->pdo->lastInsertId();

        return is_numeric($id) ? (int) $id : $id;
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "DELETE FROM {$table} WHERE {$col} = ?";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        $table = $this->dialect->quoteIdentifier($type);
        $col = $this->dialect->quoteIdentifier($idField);
        $sql = "SELECT 1 FROM {$table} WHERE {$col} = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);

        return $stmt->fetchColumn() !== false;
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, $bindings);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Runs a write statement (UPDATE, DELETE, an upsert) and returns the affected rows.
     * Ints, bools and nulls are bound with their type, so SQL arithmetic and
     * comparisons on bound values work as numbers (SQLite orders any text above
     * any number).
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            });
        }
        $start = hrtime(true);
        $stmt->execute();
        $this->collector?->logQuery($sql, $bindings, (hrtime(true) - $start) / 1_000_000);

        return $stmt->rowCount();
    }
}
