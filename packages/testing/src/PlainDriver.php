<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

/**
 * Wraps any StorageDriver and exposes only the required StorageDriver methods, so none of the
 * capability interfaces (RunsSql, ConditionalUpdates, ...) the inner driver implements show
 * through. Use it in tests to prove what DataManager and other callers do on a storage that
 * has the bare minimum: the documented fallback, or UnsupportedStorage.
 *
 * @since 0.78
 */
final class PlainDriver implements StorageDriver
{
    public function __construct(private StorageDriver $inner) {}

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
        $this->inner->save($type, $id, $data, $idField);
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
}
