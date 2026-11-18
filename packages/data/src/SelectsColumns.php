<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that reads one column of the records a query selects. QueryBuilder::pluck() uses it
 * where the column's values are what a record reads (QueryBuilder decides); a driver without it
 * still works: pluck() reads the records and takes the field. Both ways give the same values.
 * The SQL drivers implement it; JSON storage does not.
 *
 * @since 0.79.0
 */
interface SelectsColumns extends StorageDriver
{
    /**
     * The $field of each record $query selects, in its order and within its limit and offset, as
     * the column holds it (no locale picked, no decoding): what findMany() would put under that key.
     *
     * @return list<mixed>
     */
    public function pluck(string $type, string $field, Query $query): array;
}
