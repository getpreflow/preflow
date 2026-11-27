<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\RecordPreviewMarks;

/**
 * The preview editor opens a linked record (a template's preview_mark(type, record)) by
 * data-folio-record (its type) and data-folio-record-id, and names it by data-folio-label: the
 * type's label for one record, in the language Folio's texts have in the render (as a block's).
 *
 * @since 0.92.0
 */
final class FolioRecordPreviewMarks implements RecordPreviewMarks
{
    public function __construct(private readonly DisplayLabels $labels) {}

    public function attributes(string $type, string $id): array
    {
        return [
            'data-folio-record' => $type,
            'data-folio-record-id' => $id,
            'data-folio-label' => $this->labels->one($type),
        ];
    }
}
