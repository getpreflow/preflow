<?php

declare(strict_types=1);

namespace Preflow\Data\Observe;

/**
 * Told about every read and write that goes through the DataManager (DataManager::observe()):
 * what a page read while it rendered, and what a write changed (the page cache).
 *
 * $storage is the driver name ('default', 'json'...), $type the table as the driver sees it
 * (a published copy is "{table}__published"). Writes are reported after they ran, also inside a
 * transaction that may still roll back: an observer must take a write as "may have changed".
 * Implementations must be cheap and must not throw.
 */
interface DataObserver
{
    /** A read of one record ($id) or of a listing (null: a query, an aggregate). */
    public function read(string $storage, string $type, ?string $id): void;

    /** A write of one record ($id) or of many or unknown ones (null). */
    public function wrote(string $storage, string $type, ?string $id): void;

    /** A raw SQL read (DataManager::raw()). */
    public function rawRead(string $storage, string $sql): void;

    /** A raw SQL write (DataManager::execute(), or raw() with a writing statement). */
    public function rawWrote(string $storage, string $sql): void;
}
