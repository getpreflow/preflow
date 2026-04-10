<?php
declare(strict_types=1);
namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Route
{
    /** @var string[] */
    public readonly array $middleware;

    /**
     * @param string[] $middleware
     */
    public function __construct(
        public readonly string $path,
        array $middleware = [],
    ) {
        $this->middleware = $middleware;
    }
}
