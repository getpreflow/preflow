<?php

declare(strict_types=1);

namespace Preflow\Publishing;

use Preflow\Data\DynamicRecord;

/**
 * An index that follows the working copies: refreshed after every save the Publisher and
 * RecordWriter make (draft, publish, discard, restore, a type without publishing), told by
 * RecordDeleter when a record is gone. The default keeps none;
 * Folio binds its translation review index.
 */
interface RecordIndex
{
    public function refresh(DynamicRecord $record): void;

    public function forget(string $type, string $id): void;
}
