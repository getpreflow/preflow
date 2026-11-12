<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Fixtures;

/** Written against older names of #[Block] and #[BlockField] (class aliases, as Folio keeps for one release). */
#[\Legacy\Blocks\Block(key: 'legacy', label: 'Legacy')]
final class LegacyNamed
{
    #[\Legacy\Blocks\BlockField(type: 'text')]
    public string $body = '';
}
