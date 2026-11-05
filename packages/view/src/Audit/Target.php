<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * Where to fix a finding that comes from content: a record ('record', its type
 * and id) or a media asset ('asset', its type and id), so an admin can link
 * straight to its editor.
 *
 * @since 0.54
 */
final readonly class Target
{
    /** @param string $kind 'record' or 'asset' */
    public function __construct(
        public string $kind,
        public string $type,
        public string $id,
    ) {}
}
