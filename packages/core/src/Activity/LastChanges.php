<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * An ActivityLog that can also say when records were last written: one cheap read for a chunk of
 * records, whatever the log keeps otherwise. The data layer's change-date backfill (`preflow
 * migrate`, step data.change-dates) asks it first for records written before change dates were
 * kept. A log without it simply has no answer (callers ask with `instanceof`), and a record the
 * log no longer keeps is left out.
 *
 * @since 0.84.0
 */
interface LastChanges extends ActivityLog
{
    /**
     * The time of each record's newest event, as the log recorded it (an ISO 8601 text in UTC).
     *
     * @param list<string> $ids the records of the subject (a type key)
     * @return array<string, string> record id => time; ids without an event are not in it
     * @since 0.84.0
     */
    public function lastChanged(string $subject, array $ids): array;
}
