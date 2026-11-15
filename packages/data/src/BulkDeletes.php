<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that deletes many records of a type in a few statements (SQL: DELETE … WHERE id IN
 * (…), in chunks). DataManager::deleteManyIn() uses it and deletes one by one on a driver
 * without it, so a third-party driver works either way.
 *
 * @since 0.77
 */
interface BulkDeletes extends StorageDriver
{
    /**
     * Deletes the records with these ids; ids no record has are skipped.
     *
     * @param list<string|int> $ids
     */
    public function deleteMany(string $type, array $ids, string $idField = 'uuid'): void;
}
