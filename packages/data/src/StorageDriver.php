<?php

declare(strict_types=1);

namespace Preflow\Data;

interface StorageDriver
{
    /**
     * @return array<string, mixed>|null
     */
    public function findOne(string $type, string $id): ?array;

    public function findMany(string $type, Query $query): ResultSet;

    /**
     * @param array<string, mixed> $data
     */
    public function save(string $type, string $id, array $data): void;

    public function delete(string $type, string $id): void;

    public function exists(string $type, string $id): bool;
}
