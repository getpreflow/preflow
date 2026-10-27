<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Publishing;

use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\VersionedStorage;

/**
 * Wraps a driver to stage races and count reads: a closure given to raceOnce() runs right before
 * the next version-checked write, as another request saving between the check and the write
 * would; findMany() calls are counted per table.
 */
final class RacingDriver implements VersionedStorage
{
    private ?\Closure $race = null;

    private int $skip = 0;

    /** @var array<string, int> */
    public array $findMany = [];

    public function __construct(private readonly VersionedStorage $inner) {}

    /** $skip: version-checked writes to let through first (a form publish: the draft save, then the publish). */
    public function raceOnce(\Closure $race, int $skip = 0): void
    {
        $this->race = $race;
        $this->skip = $skip;
    }

    public function saveIfVersion(string $type, string|int $id, array $data, string $versionField, int $expected, string $idField = 'uuid'): bool
    {
        if ($this->race !== null && $this->skip-- <= 0) {
            $race = $this->race;
            $this->race = null;
            $race();
        }

        return $this->inner->saveIfVersion($type, $id, $data, $versionField, $expected, $idField);
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        $this->findMany[$type] = ($this->findMany[$type] ?? 0) + 1;

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

    public function rawQuery(string $sql, array $bindings = []): array
    {
        return $this->inner->rawQuery($sql, $bindings);
    }
}
