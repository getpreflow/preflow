<?php

declare(strict_types=1);

namespace Preflow\Members\Tests\Support;

use Preflow\Data\IdKind;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StatesIdKinds;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;

/** A storage that numbers every record itself and says so (StatesIdKinds). */
final class NumberingMembersDriver implements StorageDriver, StatesIdKinds
{
    public function __construct(private StorageDriver $inner) {}

    public function idKindOf(TypeDefinition $def): ?IdKind
    {
        return IdKind::Int;
    }

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
