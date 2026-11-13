<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/**
 * The public addresses of records, told by the Publisher, RecordWriter and RecordDeleter what
 * changed: an address book behind automatic redirects, and a page cache whose pages at those
 * addresses change.
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

    /** After an unpublish or a delete: the record's old addresses lead to $target, or answer 404 without one. */
    public function removed(string $type, string $id, ?RemovalTarget $target): void;

    /**
     * The addresses the record had ($before returns its snapshot before, null when there was none)
     * and has ($after, null when it leaves the site): the pages at them change (a record that takes
     * over an address, one that leaves it). $before reads the snapshot only when called; call it
     * here, not later (on an unpublish the snapshot is deleted next).
     *
     * @param \Closure(): ?DynamicRecord $before
     */
    public function changed(\Closure $before, ?DynamicRecord $after): void;

    /**
     * Where $target leads for a delete (RecordDeleter), decided before anything is deleted (an
     * address of a page may become its record); null when it leads to one of the records the
     * delete removes, whose old links would end in a 404 too.
     *
     * @param array<string, list<string>> $deleting type => the ids the delete removes
     */
    public function finalTarget(?RemovalTarget $target, array $deleting): ?RemovalTarget;
}
