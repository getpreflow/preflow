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
        protected readonly QueryCompiler $compiler = new QueryCompiler(),
    ) {}

    public function findOne(string $type, string $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM \"{$type}\" WHERE uuid = ? LIMIT 1");
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
        $data['uuid'] = $id;

        if ($this->exists($type, $id)) {
            $this->update($type, $id, $data);
        } else {
            $this->insert($type, $data);
        }
    }

    public function delete(string $type, string $id): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM \"{$type}\" WHERE uuid = ?");
        $stmt->execute([$id]);
    }

    public function exists(string $type, string $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM \"{$type}\" WHERE uuid = ? LIMIT 1");
        $stmt->execute([$id]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insert(string $type, array $data): void
    {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $colsSql = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
        $phSql = implode(', ', $placeholders);

        $stmt = $this->pdo->prepare("INSERT INTO \"{$type}\" ({$colsSql}) VALUES ({$phSql})");
        $stmt->execute(array_values($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function update(string $type, string $id, array $data): void
    {
        unset($data['uuid']);
        $setParts = [];
        $values = [];

        foreach ($data as $col => $val) {
            $setParts[] = "\"{$col}\" = ?";
            $values[] = $val;
        }

        $values[] = $id;
        $setSql = implode(', ', $setParts);

        $stmt = $this->pdo->prepare("UPDATE \"{$type}\" SET {$setSql} WHERE uuid = ?");
        $stmt->execute($values);
    }
}
