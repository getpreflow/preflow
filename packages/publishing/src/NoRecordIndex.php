<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/** The default RecordIndex: none. */
final class NoRecordIndex implements RecordIndex
{
    public function refresh(DynamicRecord $record): void {}

    public function forget(string $type, string $id): void {}
}
