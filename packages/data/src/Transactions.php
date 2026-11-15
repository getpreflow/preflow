<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage with transactions: writes that commit together or not at all.
 *
 * transaction() inside an open transaction (one the caller opened, through the driver or on its
 * connection) joins it: $fn runs as it is, and commit and rollback stay with whoever opened it.
 * To write across several storages, use DataManager::transaction(). A storage without
 * transactions (JSON files) need not implement it: DataManager::transaction() then simply runs
 * the writes. Savepoints isolates writes inside an open transaction.
 *
 * @since 0.78
 */
interface Transactions extends StorageDriver
{
    /**
     * Begins a transaction, runs $fn, commits and returns its result. When $fn throws, rolls
     * back and rethrows; when the commit fails, rolls back (where the connection still has the
     * transaction) and rethrows. Inside an open transaction, $fn runs as it is (joined).
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function transaction(\Closure $fn): mixed;

    /** Whether a transaction is open on the storage's connection, whoever opened it. */
    public function inTransaction(): bool;
}
