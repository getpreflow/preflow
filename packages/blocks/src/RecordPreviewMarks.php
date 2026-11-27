<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/**
 * The attributes a linked record's element gets in preview mode, so an editor can open that record
 * from the page (a teaser of a post, a team member's card). A template marks the element itself with
 * preview_mark(type, record); nothing is marked on its own. DefaultRecordPreviewMarks adds
 * data-record-type and data-record-id; Folio's editor binds its own.
 *
 * @since 0.92.0
 */
interface RecordPreviewMarks
{
    /**
     * @param string $type the record's type key
     * @param string $id the record's id
     * @return array<string, string> attribute name (data-…) => value, escaped by preview_mark()
     */
    public function attributes(string $type, string $id): array;
}
