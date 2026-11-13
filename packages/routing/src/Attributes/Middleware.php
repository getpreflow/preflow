<?php
declare(strict_types=1);
namespace Preflow\Routing\Attributes;

use Preflow\Routing\MiddlewareWith;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Middleware
{
    /** @var list<string|MiddlewareWith> */
    public readonly array $middleware;

    /** Class names, or a class with constructor arguments: new MiddlewareWith(AuthMiddleware::class, guard: 'employers') */
    public function __construct(string|MiddlewareWith ...$middleware)
    {
        $this->middleware = $middleware;
    }
}
