<?php

declare(strict_types=1);

namespace Preflow\Data\Driver;

/**
 * A Dialect that writes its own insert-if-new statement for PdoDriver::insertNew() (InsertsNew).
 * Without it, insertNew() runs a plain INSERT and reads a primary-key failure
 * (Dialect::isPrimaryKeyViolation()) as "the id exists"; that needs a database whose failed
 * statement leaves the transaction usable, as SQLite and MySQL do. A database whose failed
 * statement aborts the transaction (PostgreSQL) implements this interface and returns
 * `INSERT … ON CONFLICT ("id") DO NOTHING`: PdoDriver then reads the number of rows written
 * (0: the id exists, nothing written) and never asks isPrimaryKeyViolation().
 *
 * An optional interface beside Dialect, so a dialect written before it keeps working unchanged.
 *
 * @since 0.80.0
 */
interface InsertIfNew
{
    /**
     * One statement inserting a row of $columns (one "?" per column, in their order) that writes
     * nothing, and does not fail, when a row with that id exists; the conflict is on $idField
     * only, so a duplicate in another unique index still fails (Dialect::uniqueViolation()).
     * The statement's row count must be 1 when the row was written and 0 when it was skipped.
     * Null: no such statement (PdoDriver then does a plain INSERT as without this interface).
     *
     * @param list<string> $columns the id column among them
     */
    public function insertNewSql(string $table, array $columns, string $idField): ?string;
}
