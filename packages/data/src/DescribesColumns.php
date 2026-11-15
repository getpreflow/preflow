<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage with a schema that can say whether a type's table has a column (SQL: the table's
 * columns). A storage without one (JSON files) takes any key and does not implement it;
 * DataManager::hasColumn() is the way to ask.
 *
 * @since 0.77
 */
interface DescribesColumns extends StorageDriver
{
    /** Whether the table of $type exists and has $column. */
    public function hasColumn(string $type, string $column): bool;
}
