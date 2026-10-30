<?php

declare(strict_types=1);

namespace Preflow\Ai\Usage;

/** Where AI calls are written down, and read back for a period. */
interface UsageLog
{
    public function record(UsageEntry $e): void;

    /**
     * Entries with $from <= at <= $to, oldest first.
     *
     * @return list<UsageEntry>
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
