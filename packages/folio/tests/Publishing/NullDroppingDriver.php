<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

/** Storage with MySQL upsert semantics: null values are not written, so old values stay. */
final class NullDroppingDriver implements StorageDriver
{
    public function __construct(private readonly StorageDriver $inner) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        return $this->inner->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $existing = $this->inner->findOne($type, $id, $idField) ?? [];
        $this->inner->save($type, $id, array_merge($existing, array_filter($data, static fn ($v) => $v !== null)), $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->inner->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->inner->rawQuery($sql, $bindings);
    }
}
