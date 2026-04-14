<?php

declare(strict_types=1);

namespace Preflow\Data;

interface StorageDriver
{
    /**
     * @return array<string, mixed>|null
     */
    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array;

    public function findMany(string $type, Query $query): ResultSet;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void;

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void;

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool;

    public function lastInsertId(): string|int;

    /**
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function rawQuery(string $sql, array $bindings = []): array;
}
