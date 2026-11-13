<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Boot\BootManifest;
use Preflow\Core\Routing\ConfiguredMiddleware;
use Psr\Http\Server\MiddlewareInterface;

/**
 * A route middleware with constructor arguments, next to plain class names:
 * `#[Middleware(new MiddlewareWith(AuthMiddleware::class, guard: 'employers'))]`, or in
 * `#[Route('/employer', middleware: [...])]`. The route gets a new instance from the container,
 * the named arguments winning over autowiring. Arguments are named, must be constructor
 * parameters and match their types strictly (no coercion: `guard: 5` is refused); route caches
 * keep them, so they must be values var_export() writes (scalars, arrays, enums). Each is checked
 * when the routes are scanned.
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
            $params[$param->getName()] = $param;
        }
        foreach ($args as $name => $value) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException("MiddlewareWith: pass the arguments for [{$class}] by name (guard: 'employers').");
            }
            if (!isset($params[$name])) {
                throw new \InvalidArgumentException("MiddlewareWith: [{$class}] has no constructor parameter [\${$name}].");
            }
            $type = $params[$name]->getType();
            if (!self::accepts($type, $value)) {
                throw new \InvalidArgumentException("MiddlewareWith: [{$class}] argument [\${$name}] must be {$type}, " . get_debug_type($value) . ' given.');
            }
        }
        // The route cache and the boot manifest write the arguments with var_export().
        if (!BootManifest::exportable($args)) {
            throw new \InvalidArgumentException("MiddlewareWith: the arguments for [{$class}] must be values a route cache can write (scalars, arrays, enums).");
        }
        $this->args = $args;
    }

    /** Whether $value passes $type without coercion (strict types); a type the scan can't judge is left to the container. */
    private static function accepts(?\ReflectionType $type, mixed $value): bool
    {
        if ($type === null) {
            return true;
        }
        if ($value === null && $type->allowsNull()) {
            return true;
        }
        foreach ($type instanceof \ReflectionUnionType ? $type->getTypes() : [$type] as $single) {
            if (!$single instanceof \ReflectionNamedType) {
                return true;
            }
            $name = $single->getName();
            $ok = match ($name) {
                'string' => is_string($value),
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'bool' => is_bool($value),
                'true' => $value === true,
                'false' => $value === false,
                'null' => $value === null,
                'array', 'iterable' => is_array($value),
                default => $single->isBuiltin() || $value instanceof $name,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
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
