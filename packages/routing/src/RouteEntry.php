<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\RouteMode;

final readonly class RouteEntry
{
    /**
     * @param string   $pattern    URI pattern (e.g., '/blog/{slug}')
     * @param string   $handler    Handler identifier (page path or 'ClassName@method')
     * @param string   $method     HTTP method (GET, POST, etc.)
     * @param RouteMode $mode      Component or Action
     * @param string[] $middleware Middleware class names
     * @param string[] $paramNames Parameter names extracted from pattern
     * @param string   $regex      Compiled regex for matching
     * @param bool     $isCatchAll Whether this route has a catch-all segment
     */
    public function __construct(
        public string $pattern,
        public string $handler,
        public string $method,
        public RouteMode $mode,
        public array $middleware = [],
        public array $paramNames = [],
        public string $regex = '',
        public bool $isCatchAll = false,
    ) {}
}
