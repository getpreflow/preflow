<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that writes itself out as text and reads such text back, through its own, prepared
 * connection: every table, its rows, indexes and the rest of the schema.
 *
 * For a store whose database tools can't restore what Preflow made: a SQLite dump of tables
 * with the collation SqliteDialect::prepare() registers fails at its first CREATE TABLE in the
 * sqlite3 tool ("no such collation sequence"), so nothing is restored. `preflow db:dump` and
 * `db:restore` call this interface. A store whose own dump and restore work on it (MySQL/MariaDB:
 * mysqldump) need not implement it. The text is the driver's own (SQL for an SQL store); only the
 * same driver promises to read it back.
 *
 * @since 0.78
 */
interface Dumps extends StorageDriver
{
    /**
     * Writes the whole store to $out (a writable stream) as text restore() reads back. Reads only;
     * the dump is one consistent snapshot.
     *
     * @param resource $out
     */
    public function dump(mixed $out): void;

    /**
     * Reads a dump() from $in (a readable stream) into this store, which must hold no tables
     * (throws \RuntimeException naming them otherwise). All or nothing: a failure leaves the store
     * empty and rethrows.
     *
     * @param resource $in
     */
    public function restore(mixed $in): void;
}
