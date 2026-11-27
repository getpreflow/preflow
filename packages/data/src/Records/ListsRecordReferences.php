<?php

declare(strict_types=1);

namespace Preflow\Data\Records;

use Preflow\Data\DynamicRecord;

/**
 * Optional for an OutputPart whose keys hold references to other records (the blocks package's
 * page areas: the assets and records their blocks name), so RecordOutput::references() lists them
 * beside the fields' (ListsReferences) and a part such as the media package's "_media" covers
 * them too.
 *
 * @since 0.93.0
 */
interface ListsRecordReferences
{
    /** @return array<string, list<string>> target type => ids its keys reference in this record */
    public function references(DynamicRecord $record): array;
}
