<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * Who deletes: staff acting on a record, or the record's owner deleting their own.
 * A relation's onSelfDelete rule applies to Self; onDelete to Staff.
 */
enum DeletedBy: string
{
    case Staff = 'staff';
    case Self = 'self';
}
