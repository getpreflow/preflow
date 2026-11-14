<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Data\DynamicRecord;
use Preflow\Data\RecordTitle;

/**
 * Resolves a human label for a record: RecordTitle in the type's default locale (its
 * titleField, else its first string field that is not hidden, falling back to the record
 * id). Shared by the matrix editor and the record-label API.
 */
final class RecordLabeler
{
    public function label(DynamicRecord $record): string
    {
        return RecordTitle::of($record);
    }
}
