<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * What RecordWriter asks about a type besides publishing: whether its records are kept in a
 * manual order (_order: a new record goes last, reorder() moves one). The default orders none;
 * Folio binds its listings ("list": {"orderable": true}).
 */
interface RecordTypes
{
    public function orderable(string $type): bool;
}
