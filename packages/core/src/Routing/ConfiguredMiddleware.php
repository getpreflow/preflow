<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

/**
 * A route middleware given with constructor arguments (routing's MiddlewareWith). The kernel
 * builds a new instance per route from the container: Container::make($class, $arguments), the
 * named arguments winning over autowiring.
 */
interface ConfiguredMiddleware
{
    /** @return class-string the middleware class */
    public function middlewareClass(): string;

    /** @return array<string, mixed> constructor arguments by parameter name */
    public function arguments(): array;
}
