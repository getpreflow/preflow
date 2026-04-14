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

    protected function executeWithLogging(\PDOStatement $stmt, string $sql, array $bindings = []): void
    {
        $start = hrtime(true);
        $stmt->execute($bindings);
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
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, [$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        // Get total count (without limit/offset)
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->pdo->prepare($countSql);
        $this->executeWithLogging($countStmt, $countSql, $countBindings);
        $total = (int) $countStmt->fetchColumn();

        // Get items
        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->pdo->prepare($sql);
        $this->executeWithLogging($stmt, $sql, $bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($items, $total);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

        if ($isEmpty) {
            unset($data[$idField]);
            $data = array_filter($data, fn ($v) => $v !== null);
            $columns = array_keys($data);
            $sql = $this->dialect->insertSql($type, $columns);
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, array_values($data));
        } else {
            if (!isset($data[$idField])) {
                $data[$idField] = $id;
            }

            // Filter out null values
            $data = array_filter($data, fn ($v) => $v !== null);

            $columns = array_keys($data);
            $sql = $this->dialect->upsertSql($type, $columns, $idField);
            $stmt = $this->pdo->prepare($sql);
            $this->executeWithLogging($stmt, $sql, array_values($data));
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
}
