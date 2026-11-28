<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Checks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block with the kinds of value the core checks: required text, a select, a select from its own tags, a link, a field shown only for a link, nested quotes, a field declared hidden. */
#[Block(key: 'section', label: 'Section')]
final class Section
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $title = '';

    #[BlockField(type: 'select', config: ['options' => ['s' => 'Small', 'l' => 'Large']])]
    public string $size = '';

    #[BlockField(type: 'tags', label: 'Possible answers')]
    public array $answers = [];

    #[BlockField(type: 'select', config: ['options_from' => 'answers'])]
    public string $answer = '';

    #[BlockField(type: 'link')]
    public string $website = '';

    #[BlockField(type: 'select', config: ['options' => ['text' => 'Text', 'link' => 'Link']])]
    public string $kind = 'text';

    #[BlockField(type: 'string', validate: ['required'], config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $button_url = '';

    #[BlockField(type: 'blocks', allowed: ['section_quote'])]
    public array $quotes = [];

    #[BlockField(type: 'string', config: ['hidden' => true])]
    public string $secret = '';
}
