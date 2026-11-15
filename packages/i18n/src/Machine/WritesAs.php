<?php

declare(strict_types=1);

namespace Preflow\I18n\Machine;

/**
 * A TextSource that records who wrote (an activity log, revisions): the JobRunner writes through
 * writeAs() with the person who queued the job (Job::$queuedBy). Sources without it get write().
 *
 * @since 0.77
 */
interface WritesAs extends TextSource
{
    /**
     * As TextSource::write(), on behalf of $by (a user id; null: nobody known).
     *
     * @param array<string,string> $texts
     */
    public function writeAs(string $id, string $to, array $texts, ?string $by): WriteOutcome;
}
