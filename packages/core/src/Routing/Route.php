<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

final readonly class Route
{
    /**
     * @param array<string, string> $parameters  Extracted route parameters
     * @param string[]              $middleware   Middleware class names for this route
     */
    public function __construct(
        public RouteMode $mode,
        public string $handler,
        public array $parameters = [],
        public array $middleware = [],
        public ?string $action = null,
    ) {}
}
