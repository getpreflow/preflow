<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\ConfiguredMiddleware;
use Psr\Http\Server\MiddlewareInterface;

/**
 * A route middleware with constructor arguments, next to plain class names:
 * `#[Middleware(new MiddlewareWith(AuthMiddleware::class, guard: 'employers'))]`, or in
 * `#[Route('/employer', middleware: [...])]`. The route gets a new instance from the container,
 * the named arguments winning over autowiring. Arguments are named and must be constructor
 * parameters; route caches keep them, so use values var_export() can write (scalars, arrays, enums).
 */
final readonly class MiddlewareWith implements ConfiguredMiddleware
{
    /** @var array<string, mixed> */
    public array $args;

    public function __construct(public string $class, mixed ...$args)
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("MiddlewareWith: class [{$class}] not found.");
        }
        if (!is_subclass_of($class, MiddlewareInterface::class)) {
            throw new \InvalidArgumentException("MiddlewareWith: [{$class}] is not a middleware (it does not implement " . MiddlewareInterface::class . ').');
        }
        $params = [];
        foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
            $params[$param->getName()] = true;
        }
        foreach (array_keys($args) as $name) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException("MiddlewareWith: pass the arguments for [{$class}] by name (guard: 'employers').");
            }
            if (!isset($params[$name])) {
                throw new \InvalidArgumentException("MiddlewareWith: [{$class}] has no constructor parameter [\${$name}].");
            }
        }
        $this->args = $args;
    }

    public function middlewareClass(): string
    {
        return $this->class;
    }

    public function arguments(): array
    {
        return $this->args;
    }

    /**
     * Rebuilt from a route cache or the boot manifest (var_export()), without checking again.
     *
     * @param array{class: class-string, args: array<string, mixed>} $state
     */
    public static function __set_state(array $state): self
    {
        $with = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $with->class = $state['class'];
        $with->args = $state['args'];

        return $with;
    }
}
