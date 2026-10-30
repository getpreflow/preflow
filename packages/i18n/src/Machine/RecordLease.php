<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A short exclusive claim on one record, so two workers never translate it at the same time.
 */
interface RecordLease
{
    /** True when the caller now holds the record; false when someone else does. A claim lapses after $ttlSeconds. */
    public function claim(string $collection, string $id, int $ttlSeconds = 600): bool;

    public function release(string $collection, string $id): void;
}
