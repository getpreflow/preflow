<?php

declare(strict_types=1);

namespace Preflow\Core\Cache;

final readonly class CacheCleared
{
    public function __construct(
        public int $entries,
        public int $bytes,
    ) {}
}
