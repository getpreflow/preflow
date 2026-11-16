<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that checks and compacts itself through its own, prepared connection.
 *
 * An operator reaches for these on a damaged or bloated store. Some stores can't be checked or
 * compacted by their database's own tools once Preflow owns them: a SQLite file's text columns
 * declare the collation SqliteDialect::prepare() registers, which the sqlite3 tool lacks, so its
 * VACUUM and PRAGMA integrity_check fail there. The `preflow db:check` and `db:vacuum` commands
 * call this interface. A store whose own tools work on it (MySQL/MariaDB: mysqlcheck, OPTIMIZE
 * TABLE) need not implement it; the commands then point to those tools.
 *
 * @since 0.78
 */
interface Maintenance extends StorageDriver
{
    /**
     * Checks the whole store (its structure, indexes and references between records) and
     * returns one line per problem found; [] when it is sound. Reads only.
     *
     * @return list<string>
     */
    public function check(): array;

    /**
     * Rebuilds the store compactly, giving back the space deleted records left. Not inside a
     * transaction (throws \LogicException there); it may take a while and hold the store's
     * write lock meanwhile.
     */
    public function compact(): void;
}
