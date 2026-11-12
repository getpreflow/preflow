<?php

declare(strict_types=1);

namespace Preflow\Blocks;

/** The BlockPreviewMarks without an editor of its own: data-block="{uid}" data-block-label="{label}". */
final class DefaultBlockPreviewMarks implements BlockPreviewMarks
{
    public function attributes(BlockDefinition $definition, array $block): array
    {
        return [
            'data-block' => (string) ($block['_uid'] ?? ''),
            'data-block-label' => $definition->label,
        ];
    }
}
