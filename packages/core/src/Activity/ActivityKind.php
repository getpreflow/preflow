<?php

declare(strict_types=1);

namespace Preflow\Core\Activity;

/**
 * What happened to a record, an asset or another subject (Activity::$kind). The values are
 * stored, so they never change.
 *
 * - Created: a new record (a copy too), a new redirect.
 * - Saved: a draft or a record saved, an autosave included (a log coalesces those, see ActivityLog).
 * - Published, Unpublished: a record went live, or was taken off the site.
 * - Scheduled: a save that set a time for the record to go live.
 * - Restored: an earlier revision copied back into the working copy, an asset's earlier file made current.
 * - Discarded: a working copy's changes thrown away (back to what is published).
 * - Deleted: a record deleted, an asset binned or deleted.
 * - Uploaded, Replaced: an asset's file came in, or was swapped.
 *
 * @since 0.77
 */
enum ActivityKind: string
{
    case Created = 'created';
    case Saved = 'saved';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Scheduled = 'scheduled';
    case Restored = 'restored';
    case Discarded = 'discarded';
    case Deleted = 'deleted';
    case Uploaded = 'uploaded';
    case Replaced = 'replaced';
}
