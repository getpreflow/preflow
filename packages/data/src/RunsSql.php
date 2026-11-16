<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A storage that runs SQL: raw queries, raw writes, and the dialect that quotes for it.
 *
 * Call it through DataManager::raw() and DataManager::execute(), which resolve the storage by
 * name, report writes to the observer and throw UnsupportedStorage when the storage does not
 * implement this interface. A storage that does not run SQL (the JSON file driver, a document
 * store) simply does not implement it.
 *
 * @since 0.78
 */
interface RunsSql extends StorageDriver
{
    /**
     * Run a SELECT-like statement and return its rows.
     *
     * @param array<int|string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function rawQuery(string $sql, array $bindings = []): array;

    /**
     * Run a write (UPDATE, DELETE, an upsert) and return the affected rows.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    /** The SQL dialect of the store: how to quote identifiers and what it supports. */
    public function dialect(): Driver\Dialect;
}
