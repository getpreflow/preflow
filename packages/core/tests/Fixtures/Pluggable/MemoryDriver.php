<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\Core\Http\NonPublicPaths;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

/** A storage driver named by class in config/data.php: one option, one service from the container. */
class MemoryDriver implements StorageDriver
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $rows = [];

    public function __construct(
        public readonly NonPublicPaths $paths,
        public readonly string $label = 'unset',
    ) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->rows[$type][(string) $id] ?? null;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        $rows = array_values($this->rows[$type] ?? []);

        return new ResultSet($rows, count($rows));
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->rows[$type][(string) $id] = [$idField => $id] + $data;
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        unset($this->rows[$type][(string) $id]);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return isset($this->rows[$type][(string) $id]);
    }

    public function lastInsertId(): string|int
    {
        return 0;
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        throw new \LogicException('No SQL in memory.');
    }
}
