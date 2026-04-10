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

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var ServiceProvider[] */
    private array $providers = [];

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->singletons[$id])) {
            return $this->singletons[$id];
        }

        $result = $this->resolve($id);

        if (isset($this->singletonKeys[$id])) {
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

    public function bind(string $abstract, string|callable $concrete): void
    {
        if (is_string($concrete)) {
            $this->bindings[$abstract] = fn () => $this->resolve($concrete);
        } else {
            $this->bindings[$abstract] = $concrete;
        }
    }

    public function singleton(string $abstract, string|callable|null $concrete = null): void
    {
        $this->singletonKeys[$abstract] = true;

        if ($concrete !== null) {
            $this->bind($abstract, $concrete);
        }
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    /**
     * @param array<string, mixed> $parameters Override parameters by name
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        if (isset($this->bindings[$abstract])) {
            return ($this->bindings[$abstract])($this, $parameters);
        }

        return $this->build($abstract, $parameters);
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

    public function registerProvider(ServiceProvider $provider): void
    {
        $provider->register($this);
        $this->providers[] = $provider;
    }

    public function bootProviders(): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($this);
        }
    }
}
