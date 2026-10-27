<?php

declare(strict_types=1);

namespace Preflow\Data\Migration\Grammar;

/** Index names of record tables, the same on every server: they must fit MySQL's 64 characters. */
trait RecordIndexNames
{
    /** {prefix}_{table}_{field}; past 64 characters the first 50 of "{table}_{field}" and a crc32 of the full name. */
    public function indexName(string $prefix, string $table, string $field): string
    {
        $name = "{$prefix}_{$table}_{$field}";
        if (mb_strlen($name) <= 64) {
            return $name;
        }

        return $prefix . '_' . mb_substr("{$table}_{$field}", 0, 50) . '_' . sprintf('%08x', crc32($name));
    }
}
