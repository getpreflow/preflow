<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/**
 * Which never-published records `publish <type>` leaves alone without --all: a reason to skip
 * one, or null to publish it. The default skips none; Folio skips the records its legacy
 * frontend "published" field marks as not public (they were drafts before publishing was on).
 */
interface PublishSkips
{
    public function reason(DynamicRecord $record): ?string;
}
