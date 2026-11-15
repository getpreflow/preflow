<?php

declare(strict_types=1);

namespace Preflow\Publishing;

/**
 * The types whose records are people (a member's account): deleting one through the
 * RecordDeleter is erasure, so the activity log forgets it and every record deleted with it
 * (ActivityLog::forget()) instead of noting Deleted. Folio binds it for the members package's type.
 *
 * @since 0.77
 */
interface PersonalTypes
{
    public function personal(string $type): bool;
}
