<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that knows when each record was last written by its own bookkeeping, without a key of
 * the record (the JSON driver: each file's modification time). The change-date backfill of
 * `preflow migrate` (ChangeDateBackfill) asks it for records written before change dates were
 * kept; a storage without it (SQL) is not asked, and its records fall back to the next source.
 * Optional: nothing else uses it.
 *
 * @since 0.84.0
 */
interface WriteTimes extends StorageDriver
{
    /**
     * @param list<string|int> $ids records of $type
     * @return array<string, \DateTimeImmutable> id => when it was last written; ids it cannot tell (missing ones too) left out
     */
    public function writeTimes(string $type, array $ids, string $idField = 'uuid'): array;
}
