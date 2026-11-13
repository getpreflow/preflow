<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/**
 * The public addresses of published records, told by the Publisher what changed: an address
 * book behind automatic redirects, and a page cache whose pages at those addresses change.
 * The default does nothing; Folio binds its redirects and page-cache tags.
 */
interface RecordAddresses
{
    /**
     * After a publish: the record's live addresses now, $previousLive its snapshot before (null:
     * it was not published). Never fails a publish.
     *
     * @return bool whether an address left the site (it now redirects)
     */
    public function sync(string $type, string $id, ?DynamicRecord $previousLive): bool;

    /** After an unpublish: the record's old addresses lead to $target, or answer 404 without one. */
    public function removed(string $type, string $id, ?RemovalTarget $target): void;

    /**
     * The addresses the record had ($before, its snapshot before) and has ($after, null when it
     * left the site): the pages at them change (a record that takes over an address, one that
     * leaves it).
     */
    public function changed(?DynamicRecord $before, ?DynamicRecord $after): void;
}
