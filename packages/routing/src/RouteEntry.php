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
     * @param list<string|MiddlewareWith> $middleware Middleware class names, or classes with arguments
     * @param string[] $paramNames Parameter names extracted from pattern
     * @param string   $regex      Compiled regex for matching
     * @param bool     $isCatchAll Whether this route has a catch-all segment
     * @param bool     $csrfExempt Whether the route is exempt from the CSRF check
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
        public bool $csrfExempt = false,
    ) {}

    /**
     * The entry as a plain array (the mode as its string value): what a route cache stores.
     * OPcache keeps such arrays in shared memory without building them per request.
     *
     * @return array{pattern: string, handler: string, method: string, mode: string, middleware: array<int, mixed>, paramNames: array<int, string>, regex: string, isCatchAll: bool, csrfExempt: bool}
     */
    public function toArray(): array
    {
        return [
            'pattern' => $this->pattern,
            'handler' => $this->handler,
            'method' => $this->method,
            'mode' => $this->mode->value,
            'middleware' => $this->middleware,
            'paramNames' => $this->paramNames,
            'regex' => $this->regex,
            'isCatchAll' => $this->isCatchAll,
            'csrfExempt' => $this->csrfExempt,
        ];
    }

    /** @param array<string, mixed> $row an entry's toArray() */
    public static function fromArray(array $row): self
    {
        return new self(
            $row['pattern'],
            $row['handler'],
            $row['method'],
            RouteMode::from($row['mode']),
            $row['middleware'],
            $row['paramNames'],
            $row['regex'],
            $row['isCatchAll'],
            $row['csrfExempt'] ?? false,
        );
    }
}
