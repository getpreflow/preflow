<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures\Values;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** A data-only block showing an image or a clip: the one its kind does not choose is hidden (show_when). */
#[Block(key: 'value_poster', label: 'Poster')]
final class Poster
{
    #[BlockField(type: 'select', config: ['options' => ['image' => 'Image', 'clip' => 'Clip']])]
    public string $kind = 'image';

    #[BlockField(type: 'asset', config: ['show_when' => ['field' => 'kind', '=' => 'image']])]
    public string $image = '';

    #[BlockField(type: 'asset', config: ['show_when' => ['field' => 'kind', '=' => 'clip']])]
    public string $clip = '';
}
