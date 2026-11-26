<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Fixtures\Blocks;

use Preflow\Blocks\Block;
use Preflow\Blocks\BlockField;

/** Data-only block with an email, phone, colour, code and JSON field (0.91). */
#[Block(key: 'contact_card', label: 'Contact card')]
final class ContactCardBlock
{
    #[BlockField(type: 'email', label: 'Email')]
    public ?string $email = null;

    /** @var array<string, mixed>|null */
    #[BlockField(type: 'phone', label: 'Phone', config: ['default_region' => 'CH'])]
    public ?array $phone = null;

    #[BlockField(type: 'color', label: 'Accent', config: ['palette' => ['Brand red' => '#c00000', 'Sky' => '#0a5ca8'], 'palette_only' => true])]
    public ?string $accent = null;

    #[BlockField(type: 'code', label: 'Snippet', config: ['language' => 'php'])]
    public ?string $snippet = null;

    #[BlockField(type: 'json', label: 'Settings')]
    public ?string $settings = null;
}
