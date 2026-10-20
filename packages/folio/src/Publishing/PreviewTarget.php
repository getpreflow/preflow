<?php

declare(strict_types=1);

namespace Preflow\Folio\Publishing;

/** What a verified share-preview token points at. */
final readonly class PreviewTarget
{
    public function __construct(
        public string $type,
        public string $id,
        public string $nonce,
        public int $expiresAt,
    ) {}
}
