<?php

declare(strict_types=1);

namespace Preflow\BlockLibrary\Tests\Fixtures\Note;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockComponent;
use Preflow\Blocks\BlockField;

/** A component block to put into columns: a translatable title (Twig and Blade templates). */
#[Block(key: 'note', label: 'Note')]
final class Note extends BlockComponent
{
    #[BlockField(type: 'string', translatable: true)]
    public string $title = '';
}
