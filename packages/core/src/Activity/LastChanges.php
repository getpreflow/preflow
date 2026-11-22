<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * An ActivityLog that can also say when records were last written: one cheap read for a page of
 * records, whatever the log keeps otherwise. An admin's list uses it for the "Changed" column
 * of a type that keeps no updated timestamp of its own. A log without it simply has no answer
 * (callers ask with `instanceof`), and a record the log no longer keeps is left out.
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
     */
    public function lastChanged(string $subject, array $ids): array;
}
