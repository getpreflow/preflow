<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that runs a check and a write of one type while no other writer of the type runs
 * one: DataManager checks unique values no index guards (translatable fields, JSON storage) and
 * writes inside it. MySQL takes a named lock (GET_LOCK), SQLite the database's write lock
 * (BEGIN IMMEDIATE), the JSON driver the type's lock file.
 */
interface ExclusiveWrites
{
    /**
     * Runs $fn holding the type's lock; another exclusive() of the type waits until it is done.
     * Nested calls for the same type run inside the lock already held.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    public function exclusive(string $type, \Closure $fn): mixed;
}
