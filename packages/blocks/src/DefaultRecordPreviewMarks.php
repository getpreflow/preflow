<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/**
 * The RecordPreviewMarks without an editor of its own: data-record-type="{type}" data-record-id="{id}".
 *
 * @since 0.92.0
 */
final class DefaultRecordPreviewMarks implements RecordPreviewMarks
{
    public function attributes(string $type, string $id): array
    {
        return [
            'data-record-type' => $type,
            'data-record-id' => $id,
        ];
    }
}
