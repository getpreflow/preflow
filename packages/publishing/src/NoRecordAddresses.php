<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** The default RecordAddresses: no address book, no page-cache tags. */
final class NoRecordAddresses implements RecordAddresses
{
    public function sync(string $type, string $id, ?DynamicRecord $previousLive): bool
    {
        return false;
    }

    public function removed(string $type, string $id, ?RemovalTarget $target): void {}

    public function changed(\Closure $before, ?DynamicRecord $after): void {}
}
