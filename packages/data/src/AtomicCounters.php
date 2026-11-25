<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that counts in one atomic step: concurrent increments of one row never lose a count.
 *
 * Call it through DataManager::incrementIn(), which reports the write to the observer and, on a
 * storage without this interface, reads, adds and writes instead: under the type's lock
 * (ExclusiveWrites) when the storage has one, else plainly (not atomic: two concurrent writers
 * may count once). PdoDriver implements it with its dialect's insert-or-update statement (or its
 * exclusive lock when the dialect has none), the JSON file driver under its type lock.
 *
 * @since 0.78
 */
interface AtomicCounters extends StorageDriver
{
    /**
     * In one atomic step: when the row $id is missing, inserts [$idField => $id] + $insert +
     * [$field => $by]; else, when $reset is given and the stored $reset->field is below
     * $reset->below, writes $reset->set and $field = $by (a restart); else adds $by to $field.
     *
     * Returns $field as read right after the write: at least this increment's result (later
     * concurrent increments may already be in it). $insert names neither $field nor $idField,
     * and $reset->set not $field (InvalidArgumentException). A stored reset field without a
     * value (null) is not below anything; a stored $field without one counts from 0.
     *
     * A count whose sum passes the 64-bit whole numbers (the stored $field read as
     * Condition::wholeNumber() reads it) throws the \OverflowException of Add::overflow(), naming
     * $field, and writes nothing (since 0.90): the JSON driver and DataManager's fallback check it
     * in PHP, PdoDriver in its statement when the dialect implements Driver\FailsOverflow (SQLite
     * and MySQL do).
     *
     * @param array<string, scalar|null> $insert the other columns of a new row
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    public function increment(string $table, string|int $id, string $field, int $by, array $insert, ?CounterReset $reset = null, string $idField = 'uuid'): int;
}
