<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block with a video embed, a table and a location (0.91). */
#[Block(key: 'venue_info', label: 'Venue info')]
final class VenueBlock
{
    /** @var array<string, mixed>|null */
    #[BlockField(type: 'embed', label: 'Video')]
    public ?array $video = null;

    /** @var array<string, mixed>|null */
    #[BlockField(type: 'table', label: 'Opening hours', config: ['max_columns' => 2])]
    public ?array $hours = null;

    /** @var array<string, mixed>|null */
    #[BlockField(type: 'location', label: 'Place')]
    public ?array $place = null;
}
