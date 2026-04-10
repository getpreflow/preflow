<?php
declare(strict_types=1);
namespace Preflow\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @var string[] */
    public readonly array $middleware;

    public function __construct(string ...$middleware)
    {
        $this->middleware = $middleware;
    }
}
