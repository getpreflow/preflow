<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that writes or deletes one record only while a Condition holds on it, in one atomic
 * step of its own: PdoDriver compiles the condition into one `UPDATE … WHERE id = ? AND (…)` or
 * `DELETE … WHERE id = ? AND (…)` (Driver\ConditionCompiler) and decides by the affected rows.
 *
 * Call it through DataManager::updateWhen() and deleteWhen(), which apply the write policy and
 * report the write. A storage without this interface needs nothing for them: on one that runs SQL
 * (RunsSql) DataManager compiles the same one statement for its dialect and runs it through
 * execute(); on any other it reads the record, checks Condition::holds() and writes, under the
 * type's lock (ExclusiveWrites) where the storage has one. The storage driver contract
 * (preflow/testing) runs the same cases through every path.
 *
 * @since 0.89.0
 */
interface ConditionalWrites extends StorageDriver
{
    /**
     * Write $set to the record $id while $when holds on it: only the named fields (the others stay
     * as stored); a value is stored as save() stores it, an Add adds to the stored whole number
     * (none counts from 0). A match that changes no value is still a match.
     *
     * @param array<string, mixed> $set field => value or Add, at least one; never the id field
     * @return bool whether it was written (false: no such record, or $when did not hold)
     */
    public function updateWhen(string $type, string|int $id, Condition $when, array $set, string $idField = 'uuid'): bool;

    /**
     * Delete the record $id while $when holds on it.
     *
     * @return bool whether it was deleted (false: no such record, or $when did not hold)
     */
    public function deleteWhen(string $type, string|int $id, Condition $when, string $idField = 'uuid'): bool;
}
