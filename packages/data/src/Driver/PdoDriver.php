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
    ) {}

    public function findOne(string $type, string $id): ?array
    {
        $table = $this->dialect->quoteIdentifier($type);
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE uuid = ? LIMIT 1");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result !== false ? $result : null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        // Get total count (without limit/offset)
        [$countSql, $countBindings] = $this->compiler->compileCount($type, $query);
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($countBindings);
        $total = (int) $countStmt->fetchColumn();

        // Get items
        [$sql, $bindings] = $this->compiler->compile($type, $query);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return new ResultSet($items, $total);
    }

    public function save(string $type, string $id, array $data): void
    {
        if (!isset($data['uuid'])) {
            $data['uuid'] = $id;
        }

        // Filter out null values
        $data = array_filter($data, fn ($v) => $v !== null);

        $columns = array_keys($data);
        $sql = $this->dialect->upsertSql($type, $columns, 'uuid');
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }

    public function delete(string $type, string $id): void
    {
        $table = $this->dialect->quoteIdentifier($type);
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE uuid = ?");
        $stmt->execute([$id]);
    }

    public function exists(string $type, string $id): bool
    {
        $table = $this->dialect->quoteIdentifier($type);
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE uuid = ? LIMIT 1");
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }
}
