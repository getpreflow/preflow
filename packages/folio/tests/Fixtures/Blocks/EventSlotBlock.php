<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block with a date and time, a time and a date range (0.91). */
#[Block(key: 'event_slot', label: 'Event slot')]
final class EventSlotBlock
{
    #[BlockField(type: 'datetime', label: 'Starts')]
    public ?string $starts_at = null;

    #[BlockField(type: 'time', label: 'Doors')]
    public ?string $doors = null;

    /** @var array<string, mixed>|null */
    #[BlockField(type: 'daterange', label: 'Stay', config: ['with_time' => true, 'zone' => 'Asia/Tokyo'])]
    public ?array $stay = null;
}
