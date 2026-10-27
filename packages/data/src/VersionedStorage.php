<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage driver that can write a record only if its stored version is the one the writer
 * read (compare-and-set). The check and the write are one step: of two writers holding the
 * same version, exactly one gets through. SQLite and MySQL check in the write's own UPDATE
 * inside a transaction, the JSON driver under a file lock.
 *
 * Sites use it through DataManager::saveTypeIfVersion(), which works on any driver.
 */
interface VersionedStorage extends StorageDriver
{
    /**
     * Write $data (as save() would) only if the stored record's $versionField equals $expected.
     * A missing or empty stored version counts as 0; a missing record matches 0 only, and is
     * then inserted (a concurrent insert of the same id makes one of them fail).
     *
     * $data carries the version it is written with (usually $expected + 1); a write that keeps
     * $expected succeeds whenever the stored version matches, even if nothing changes.
     *
     * @param array<string, mixed> $data must hold $versionField
     * @return bool false when the stored version was another one (nothing was written)
     */
    public function saveIfVersion(string $type, string|int $id, array $data, string $versionField, int $expected, string $idField = 'uuid'): bool;
}
