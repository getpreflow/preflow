<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that can say whether a type's table exists and has a column (SQL: the table's
 * columns; JSON: the type's directory, and any key is a column). A storage that cannot says
 * neither: DataManager::hasColumn() and hasTable() answer true for it.
 *
 * @since 0.77
 */
interface DescribesColumns extends StorageDriver
{
    /** Whether the table of $type exists and has $column. */
    public function hasColumn(string $type, string $column): bool;

    /**
     * Whether the storage has the table (SQL) or the place (JSON: the type's directory, made by
     * its first write) of $type. DataManager::hasTable() is the way to ask.
     *
     * @since 0.78
     */
    public function hasTable(string $type): bool;
}
