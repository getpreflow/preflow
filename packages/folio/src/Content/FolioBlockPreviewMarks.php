<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Blocks\BlockDefinition;
use Preflow\Blocks\BlockPreviewMarks;
use Preflow\Folio\FolioText;

/** The preview editor finds a block by data-folio-block and names it by data-folio-label (in the admin's language). */
final class FolioBlockPreviewMarks implements BlockPreviewMarks
{
    public function attributes(BlockDefinition $definition, array $block): array
    {
        return [
            'data-folio-block' => (string) ($block['_uid'] ?? ''),
            'data-folio-label' => FolioText::label($definition->label),
        ];
    }
}
