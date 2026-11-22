<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A driver that remembers facts about a table's columns (MySQL's information_schema reads) and
 * can be told they changed. Code that alters, creates or drops a table through the driver's
 * connection (RecordTableSync) calls it, so a long-running process (the translation worker,
 * `migrate` itself) never queries by the old column facts.
 *
 * @since 0.78
 */
interface RemembersColumns
{
    /**
     * Drops what is remembered of $table (null: of every table); the next query reads it again. A
     * class that overrides an implementation it inherits (a PdoDriver subclass) calls
     * parent::forgetColumns($table), so what the parent remembers is dropped too.
     */
    public function forgetColumns(?string $table = null): void;
}
