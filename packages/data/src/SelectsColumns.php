<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that reads one column of the records a query selects, and that runs a subquery of
 * its own tables inside another query. QueryBuilder::pluck() and whereIn() with a subquery use
 * it; a driver without it still works: pluck() reads the records and takes the field, and a
 * subquery is run first and passed as a list (also the way for a subquery on another driver).
 * Both ways give the same rows. The SQL drivers implement it; JSON storage does not.
 *
 * A driver that implements it must also accept a Subselect as the value of an IN / NOT IN
 * condition (Query::whereInSubselect()) in every query it runs, and give the same rows as the
 * list of the values pluck() returns for it, a null among them left out.
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
