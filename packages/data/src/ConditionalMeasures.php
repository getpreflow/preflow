<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * An aggregating storage driver that honours a measure's own condition (the `condition` of
 * AggregateQuery::measures(): a Query holding only that measure's conditions; the key is absent for a measure without one):
 * it computes the measure over the group's records that meet it, with the same text rule and
 * numbers as the query's filters. A driver without it makes AggregateQuery::get() throw for a
 * conditional measure, so it never silently counts every record.
 *
 * @since 0.79.0
 */
interface ConditionalMeasures extends AggregatingStorage {}
