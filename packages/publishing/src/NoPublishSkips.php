<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** The default PublishSkips: every never-published record is published. */
final class NoPublishSkips implements PublishSkips
{
    public function reason(DynamicRecord $record): ?string
    {
        return null;
    }
}
