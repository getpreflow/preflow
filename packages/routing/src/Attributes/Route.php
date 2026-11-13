<?php
declare(strict_types=1);
namespace Preflow\Routing\Attributes;

use Preflow\Routing\MiddlewareWith;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Route
{
    /** @var list<string|MiddlewareWith> */
    public readonly array $middleware;

    /**
     * @param list<string|MiddlewareWith> $middleware class names, or classes with arguments (MiddlewareWith)
     */
    public function __construct(
        public readonly string $path,
        array $middleware = [],
    ) {
        foreach ($middleware as $mw) {
            if (!is_string($mw) && !$mw instanceof MiddlewareWith) {
                throw new \InvalidArgumentException("#[Route('{$path}')] middleware: a class name or a MiddlewareWith, not " . get_debug_type($mw) . '.');
            }
        }
        $this->middleware = array_values($middleware);
    }
}
