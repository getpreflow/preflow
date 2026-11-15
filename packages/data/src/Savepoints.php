<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage with transactions that can isolate a few writes inside a caller's open one.
 *
 * Writes that must never break the caller's transaction (an activity row, a log) run through
 * savepoint(): what they write commits or rolls back with the caller's transaction, and their
 * failure undoes only their own writes, leaving the transaction usable (PostgreSQL would abort the
 * whole transaction on any failed statement otherwise). A storage without transactions (JSON
 * files) need not implement it: there the writes simply run.
 *
 * @since 0.77
 */
interface Savepoints
{
    /**
     * Runs $fn. When a transaction is open on the storage, under a savepoint: released when $fn
     * returns, rolled back to (and released) when it throws. Without one, $fn runs as it is.
     * $fn's failure is rethrown.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function savepoint(\Closure $fn): mixed;
}
