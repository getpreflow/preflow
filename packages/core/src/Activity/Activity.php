<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * One event in the activity log.
 *
 * - $subject: what kind of thing it happened to: a record's type key ("page"), "asset", "redirect".
 * - $id: the record's (asset's, redirect's) id.
 * - $title: its title at the time of the event (records: RecordTitle; assets: the file name;
 *   redirects: the old address), so a deleted record still reads by name.
 * - $kind: what happened.
 * - $changed: the fields that changed, in order (empty when nothing says).
 * - $by: who did it (a user id), null for the system (the scheduler, a script without a user).
 * - $at: when, as DATE_ATOM; '' lets the log set the time it records it.
 *
 * @since 0.77
 */
final readonly class Activity
{
    /** @param list<string> $changed */
    public function __construct(
        public string $subject,
        public string $id,
        public string $title,
        public ActivityKind $kind,
        public array $changed = [],
        public ?string $by = null,
        public string $at = '',
    ) {}
}
