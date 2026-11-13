<?php

declare(strict_types=1);

namespace Preflow\Core\Container;

use Psr\Container\ContainerInterface;
use Preflow\Core\Container\Exceptions\ContainerException;
use Preflow\Core\Container\Exceptions\NotFoundException;

final class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $singletons = [];

    /** @var array<string, true> */
    private array $singletonKeys = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, bool> whether a class carries #[Singleton], by class */
    private array $sharedClasses = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var array<string, string> the class an id is bound to with bind()/singleton(), by id */
    private array $concretes = [];

    /** @var array<string, bool> whether a callable binding takes make()'s parameters (a second parameter), by id */
    private array $takesParameters = [];

    /** @var ServiceProvider[] */
    private array $providers = [];

    /** @var array<class-string, true> */
    private array $providerClasses = [];

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->singletons[$id])) {
            return $this->singletons[$id];
        }

        $result = $this->resolve($id);

        if (isset($this->singletonKeys[$id]) || (!isset($this->bindings[$id]) && $this->isSharedClass($id))) {
            $this->singletons[$id] = $result;
        }

        return $result;
    }

    public function has(string $id): bool
    {
        if (isset($this->instances[$id]) || isset($this->bindings[$id])) {
            return true;
        }

        return class_exists($id);
    }

    /** Whether the id has a shared instance already: set with instance(), or a singleton built on an earlier get(). */
    public function resolved(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->singletons[$id]);
    }

    /** Whether something is bound or set for the id (unlike has(), which is also true for any autowirable class). */
    public function bound(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    /** A new binding wins: an instance already resolved from an earlier singleton binding is dropped. */
    public function bind(string $abstract, string|callable $concrete): void
    {
        unset($this->singletons[$abstract], $this->concretes[$abstract], $this->takesParameters[$abstract]);
        if (is_string($concrete)) {
            $this->bindings[$abstract] = fn () => $this->resolve($concrete);
            $this->concretes[$abstract] = $concrete;
        } else {
            $this->bindings[$abstract] = $concrete;
        }
    }

    /** Shared from first resolution; re-binding drops an instance already resolved, so the last binding wins. */
    public function singleton(string $abstract, string|callable|null $concrete = null): void
    {
        $this->singletonKeys[$abstract] = true;
        unset($this->singletons[$abstract]);

        if ($concrete !== null) {
            $this->bind($abstract, $concrete);
        }
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * A new object; the parameters, by name, win over autowiring. An id bound to a class passes
     * them on to that class; a callable binding gets them as its second argument, and one that
     * takes none fails rather than drop them.
     *
     * @param array<string, mixed> $parameters Override parameters by name
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if ($parameters !== [] && isset($this->concretes[$abstract])) {
            $concrete = $this->concretes[$abstract];
            if ($concrete === $abstract) {
                return $this->build($concrete, $parameters);
            }
            // Following the bound classes, as resolve() does: bind(A, B) with bind(B, A) is a cycle.
            if (isset($this->resolving[$abstract])) {
                throw new ContainerException("Circular dependency detected while resolving [{$abstract}].");
            }
            $this->resolving[$abstract] = true;
            try {
                return $this->make($concrete, $parameters);
            } finally {
                unset($this->resolving[$abstract]);
            }
        }
        if (isset($this->bindings[$abstract])) {
            if ($parameters !== [] && !$this->bindingTakesParameters($abstract)) {
                throw new ContainerException("[{$abstract}] is bound to a factory that takes no parameters: it cannot be made with ["
                    . implode(', ', array_keys($parameters)) . '].');
            }

            return ($this->bindings[$abstract])($this, $parameters);
        }
        if ($parameters !== [] && isset($this->instances[$abstract]) && !class_exists($abstract)) {
            throw new ContainerException("[{$abstract}] is a registered instance, not a class: it cannot be made with parameters.");
        }

        return $this->build($abstract, $parameters);
    }

    private function bindingTakesParameters(string $abstract): bool
    {
        if (!isset($this->takesParameters[$abstract])) {
            $function = new \ReflectionFunction(\Closure::fromCallable($this->bindings[$abstract]));
            $this->takesParameters[$abstract] = $function->isVariadic() || $function->getNumberOfParameters() >= 2;
        }

        return $this->takesParameters[$abstract];
    }

    /** A class marked #[Singleton] is shared when autowired. */
    private function isSharedClass(string $id): bool
    {
        return $this->sharedClasses[$id] ??= class_exists($id)
            && (new \ReflectionClass($id))->getAttributes(Attributes\Singleton::class) !== [];
    }

    private function resolve(string $abstract): mixed
    {
        if (isset($this->resolving[$abstract])) {
            throw new ContainerException(
                "Circular dependency detected while resolving [{$abstract}]."
            );
        }

        $this->resolving[$abstract] = true;

        try {
            if (isset($this->bindings[$abstract])) {
                return ($this->bindings[$abstract])($this);
            }

            return $this->build($abstract);
        } finally {
            unset($this->resolving[$abstract]);
        }
    }

    /**
     * @param array<string, mixed> $parameters Override parameters by name
     */
    private function build(string $abstract, array $parameters = []): object
    {
        if (!class_exists($abstract)) {
            throw new NotFoundException(
                "Class or binding [{$abstract}] not found in container."
            );
        }

        $reflector = new \ReflectionClass($abstract);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException(
                "Class [{$abstract}] is not instantiable."
            );
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $abstract();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();

            if (array_key_exists($name, $parameters)) {
                $args[] = $parameters[$name];
                continue;
            }

            $args[] = $this->resolveParameter($param, $abstract);
        }

        return $reflector->newInstanceArgs($args);
    }

    private function resolveParameter(\ReflectionParameter $param, string $context): mixed
    {
        // Check for #[Config] attribute
        $configAttrs = $param->getAttributes(Attributes\Config::class);
        if ($configAttrs !== []) {
            return $this->resolveConfigAttribute($configAttrs[0]->newInstance());
        }

        // Check for #[Env] attribute
        $envAttrs = $param->getAttributes(Attributes\Env::class);
        if ($envAttrs !== []) {
            return $this->resolveEnvAttribute($envAttrs[0]->newInstance());
        }

        // Fall back to type-based autowiring
        $type = $param->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new ContainerException(
            "Unable to resolve parameter [\${$param->getName()}] "
            . "in class [{$context}]."
        );
    }

    private function resolveConfigAttribute(Attributes\Config $attr): mixed
    {
        if (!$this->has(\Preflow\Core\Config\Config::class)) {
            throw new ContainerException(
                "Cannot resolve #[Config] attribute: Config instance not registered."
            );
        }

        $config = $this->get(\Preflow\Core\Config\Config::class);

        return $config->get($attr->key, $attr->default);
    }

    private function resolveEnvAttribute(Attributes\Env $attr): mixed
    {
        $value = getenv($attr->name);

        if ($value === false) {
            if ($attr->default !== null) {
                return $attr->default;
            }

            throw new ContainerException(
                "Environment variable [{$attr->name}] is not set and no default provided."
            );
        }

        return $value;
    }

    /**
     * Registers a provider once per class: a second instance of an already
     * registered class is ignored, so a package can register a provider it
     * depends on (Folio registers media) whether or not the app lists it too.
     */
    public function registerProvider(ServiceProvider $provider): void
    {
        if ($this->hasProvider($provider::class)) {
            return;
        }
        $this->providerClasses[$provider::class] = true;
        $provider->register($this);
        $this->providers[] = $provider;
    }

    /** @param class-string<ServiceProvider> $class */
    public function hasProvider(string $class): bool
    {
        return isset($this->providerClasses[$class]);
    }

    public function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($this);
        }
    }
}
