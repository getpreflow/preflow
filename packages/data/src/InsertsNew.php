<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that writes a record only when no record has its id yet, in one step: of two writers
 * of one id exactly one writes, the other is told. Every built-in driver implements it (SQL: a
 * plain INSERT that fails on the primary key; JSON files: a create under the type's lock).
 * DataManager::insert(), insertIgnoring() and insertMany() use it; a driver without it gets a
 * check, then a write, which a second writer can get in between.
 *
 * @since 0.79.0
 */
interface InsertsNew extends StorageDriver
{
    /**
     * Write $data as the record with the id in $data[$idField], unless a record with that id
     * exists. A record with the id always gives false, also when another unique index would fail
     * too (a database reports the first failing index, which need not be the id's). A new id whose
     * value is taken in another unique index is still the driver's UniqueViolation.
     *
     * A false leaves a transaction the caller opened usable: insertMany() goes on with the next
     * record in it. A driver whose database aborts the transaction on a failed statement
     * (PostgreSQL) must not let the duplicate fail the statement: `ON CONFLICT DO NOTHING`, or a
     * savepoint around the write.
     *
     * @param array<string, mixed> $data holds the id under $idField
     * @return bool true when written, false when the id exists (the stored record is untouched)
     */
    public function insertNew(string $type, array $data, string $idField = 'uuid'): bool;
}
