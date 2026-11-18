<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * One field of the records another query selects, as the value of an IN / NOT IN condition that
 * a database runs itself (`IN (SELECT field FROM table WHERE ...)`), instead of a list the caller
 * fetched first. Built by QueryBuilder::whereIn() when the outer and the inner query sit on one
 * driver that implements SelectsSubqueries; such a driver compiles it. The inner query has no limit
 * and no offset (MySQL refuses them in an IN subquery); a null value of the field is left out.
 *
 * @since 0.79.0
 */
final class Subselect
{
    public function __construct(
        public readonly string $table,
        public readonly string $field,
        public readonly Query $query,
    ) {}
}
