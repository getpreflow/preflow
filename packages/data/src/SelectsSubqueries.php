<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that runs a subquery on its own tables inside another query: it accepts a Subselect
 * (Query::whereInSubselect()) as the value of an IN / NOT IN condition in every query it runs, and
 * gives the rows the fallback gives (QueryBuilder::whereIn() runs the subquery first and passes
 * its stored values as a list): the inner column compared by the outer field's rule, a null among
 * the inner values left out. Without it whereIn() with a subquery uses the fallback, which also
 * serves a subquery on another driver or with a limit. The SQL drivers implement it.
 *
 * It is independent of SelectsColumns: a driver may offer either. A driver without SQL may resolve
 * a Subselect by reading the inner column itself (its own pluck()) and treating the result as a list.
 *
 * @since 0.79.0
 */
interface SelectsSubqueries extends StorageDriver {}
