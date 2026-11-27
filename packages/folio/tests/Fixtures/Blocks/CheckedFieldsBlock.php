<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block with a value of each field type whose rules Folio brings to the core: a date, a stepped number, tags, a select. */
#[Block(key: 'checked_fields', label: 'Checked fields')]
final class CheckedFieldsBlock
{
    #[BlockField(type: 'string', validate: ['required'])]
    public string $title = '';

    #[BlockField(type: 'date')]
    public string $day = '';

    #[BlockField(type: 'number', config: ['step' => 5])]
    public ?int $count = null;

    #[BlockField(type: 'tags', config: ['max' => 2])]
    public array $labels = [];

    #[BlockField(type: 'select', config: ['options' => ['s' => 'Small', 'l' => 'Large']])]
    public string $size = '';
}
