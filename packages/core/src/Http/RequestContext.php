<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

final readonly class RequestContext
{
    public function __construct(
        public string $path,
        public string $method,
    ) {}
}
