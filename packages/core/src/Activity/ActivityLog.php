<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * What happened to the site's content, newest first: who saved, published, deleted or uploaded
 * what, and when. Writers (publishing's RecordWriter, RecordDeleter and Publisher, media's
 * MediaLibrary, an admin's redirects, an app's own code) call record(); an admin reads recent().
 *
 * Core binds NullActivityLog (nothing is kept); the data layer binds its StoredActivityLog when
 * the site has a data configuration. A site or package may bind its own implementation.
 *
 * A writer never lets the log fail its write: it catches what record() throws and logs it.
 *
 * An implementation:
 * - sets Activity::$at when it is '' (the time of recording);
 * - may coalesce: a Saved by the same person on the same record soon after the last event of
 *   that record updates that row (changed fields merged, the time moved: the row moves to the
 *   top) instead of adding one;
 * - keeps record() cheap: no pruning there; prune() does that, run on a schedule
 *   (`php preflow activity:prune`, also part of `members:prune`).
 *
 * @since 0.77
 */
interface ActivityLog
{
    public function record(Activity $activity): void;

    /**
     * Up to $limit events, newest first (by time, then a fixed tie-break). Pages never overlap
     * or skip because of events recorded in between: a new event sorts above the cursor. A row
     * that coalesces moves to the top and so leaves the later pages (it shows on the first).
     *
     * @param list<string>|null $subjects only these subjects; null: all, []: none
     * @param string|null $before the cursor of the previous page (ActivityPage::$next); an
     *                            unreadable one reads from the start
     */
    public function recent(int $limit, ?array $subjects = null, ?string $before = null): ActivityPage;

    /**
     * Deletes what the log no longer keeps (its retention); returns how many events went. Run on
     * a schedule, never while recording or reading.
     */
    public function prune(): int;

    /**
     * Removes every event of one record (erasure for privacy: a member's account and what was
     * deleted with it). Its title is no longer kept anywhere in the log.
     */
    public function forget(string $subject, string $id): void;
}
