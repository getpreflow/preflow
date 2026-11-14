<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A block with a translatable tags field: refused (one tag list serves every language). */
#[Block(key: 'translated_tags', label: 'Translated tags', icon: 'tag')]
final class TranslatedTagsBlock
{
    #[BlockField(type: 'tags', translatable: true)]
    public array $topics = [];
}
