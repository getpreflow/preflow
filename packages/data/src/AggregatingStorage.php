<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage driver that can group records and compute counts, sums, averages, minima and
 * maxima over them (AggregateQuery). Drivers without it make AggregateQuery::get() throw, so a
 * third-party driver never silently returns wrong numbers.
 */
interface AggregatingStorage extends StorageDriver
{
    /**
     * One row per group, keyed by the group fields' names and the measures' aliases, in the
     * query's orders() and within its limit and offset. Values may come back as the storage
     * holds them (numbers as text, say): AggregateQuery::get() gives them their types.
     *
     * @return list<array<string, mixed>>
     */
    public function aggregate(AggregateQuery $query): array;
}
