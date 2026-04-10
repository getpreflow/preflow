# Phase 1: preflow/core — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation package (`preflow/core`) — DI container with autowiring and attribute injection, config loader, PSR-15 middleware pipeline, error handler, and the dual-mode kernel.

**Architecture:** Monorepo with packages under `packages/`. Each package has its own `composer.json` and can be published independently. Root `composer.json` uses path repositories for cross-package development. The core package depends only on PSR interfaces and one PSR-7 implementation.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, PSR-7 (nyholm/psr7), PSR-11, PSR-15, PSR-17

---

## File Structure

```
packages/core/
├── src/
│   ├── Kernel.php                          — Dual-mode request handler
│   ├── Application.php                     — Bootstrap and boot sequence
│   ├── Container/
│   │   ├── Container.php                   — PSR-11 DI container with autowiring
│   │   ├── ServiceProvider.php             — Abstract service provider base
│   │   ├── Attributes/
│   │   │   ├── Config.php                  — #[Config('key')] injection attribute
│   │   │   └── Env.php                     — #[Env('NAME')] injection attribute
│   │   └── Exceptions/
│   │       ├── ContainerException.php      — PSR-11 ContainerException
│   │       └── NotFoundException.php       — PSR-11 NotFoundException
│   ├── Config/
│   │   └── Config.php                      — Array-based config with dot notation
│   ├── Http/
│   │   ├── MiddlewarePipeline.php          — PSR-15 middleware dispatcher
│   │   └── Emitter.php                     — Response emitter (sends to browser)
│   ├── Error/
│   │   ├── ErrorHandler.php                — Exception handler (dev/prod)
│   │   ├── DevErrorRenderer.php            — Rich HTML error page for dev
│   │   └── ProdErrorRenderer.php           — Minimal error page for prod
│   ├── Routing/
│   │   ├── Route.php                       — Route value object
│   │   ├── RouteMode.php                   — Enum: Component | Action
│   │   └── RouterInterface.php             — Contract for preflow/routing package
│   └── Exceptions/
│       ├── HttpException.php               — Base HTTP exception with status code
│       ├── NotFoundHttpException.php        — 404
│       ├── ForbiddenHttpException.php       — 403
│       ├── UnauthorizedHttpException.php    — 401
│       └── SecurityException.php           — Security violation
├── tests/
│   ├── Container/
│   │   ├── ContainerTest.php
│   │   ├── AttributeInjectionTest.php
│   │   └── ServiceProviderTest.php
│   ├── Config/
│   │   └── ConfigTest.php
│   ├── Http/
│   │   └── MiddlewarePipelineTest.php
│   ├── Error/
│   │   └── ErrorHandlerTest.php
│   └── KernelTest.php
└── composer.json
```

---

### Task 1: Monorepo Scaffolding

**Files:**
- Create: `composer.json` (root)
- Create: `phpunit.xml` (root)
- Create: `packages/core/composer.json`
- Create: `packages/core/src/.gitkeep` (placeholder)
- Create: `packages/core/tests/.gitkeep` (placeholder)

- [ ] **Step 1: Initialize git repository**

```bash
cd /Users/smyr/Sites/gbits/flopp
git init
```

- [ ] **Step 2: Create root composer.json**

Create `composer.json`:

```json
{
    "name": "preflow/monorepo",
    "description": "Preflow framework monorepo — development only",
    "type": "project",
    "license": "MIT",
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": {
        "php": ">=8.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "preflow/core": "@dev"
    },
    "repositories": [
        {
            "type": "path",
            "url": "packages/core",
            "options": { "symlink": true }
        }
    ],
    "autoload": {},
    "autoload-dev": {},
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "php-http/discovery": true
        }
    }
}
```

Note: We use PHP 8.4 as the minimum in composer.json because Composer validates against `php_version` constraints. PHP 8.5 features will be used in code and enforced via CI. Update to `>=8.5` once PHP 8.5 is installable in the dev environment.

- [ ] **Step 3: Create packages/core/composer.json**

Create `packages/core/composer.json`:

```json
{
    "name": "preflow/core",
    "description": "Preflow framework core — kernel, container, config, middleware, error handling",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "psr/container": "^2.0",
        "psr/http-message": "^2.0",
        "psr/http-server-handler": "^1.0",
        "psr/http-server-middleware": "^1.0",
        "psr/http-factory": "^1.0",
        "nyholm/psr7": "^1.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\Core\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\Core\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 4: Create PHPUnit config**

Create `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
>
    <testsuites>
        <testsuite name="Core">
            <directory>packages/core/tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>packages/core/src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 5: Create directory structure and install dependencies**

```bash
mkdir -p packages/core/src packages/core/tests
composer install
```

- [ ] **Step 6: Create .gitignore and commit**

Create `.gitignore`:

```
/vendor/
/packages/*/vendor/
.phpunit.result.cache
.phpunit.cache/
composer.lock
.env
.DS_Store
```

```bash
git add .
git commit -m "feat: initialize monorepo with preflow/core package scaffold"
```

---

### Task 2: Container — Basic Autowiring

**Files:**
- Create: `packages/core/src/Container/Exceptions/ContainerException.php`
- Create: `packages/core/src/Container/Exceptions/NotFoundException.php`
- Create: `packages/core/src/Container/Container.php`
- Create: `packages/core/tests/Container/ContainerTest.php`

- [ ] **Step 1: Write the failing test for basic autowiring**

Create `packages/core/tests/Container/ContainerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\Exceptions\ContainerException;
use Preflow\Core\Container\Exceptions\NotFoundException;

class SimpleService
{
}

class ServiceWithDependency
{
    public function __construct(
        public readonly SimpleService $simple,
    ) {}
}

class ServiceWithNestedDeps
{
    public function __construct(
        public readonly ServiceWithDependency $service,
        public readonly SimpleService $simple,
    ) {}
}

interface SomeInterface
{
}

class ServiceWithInterface
{
    public function __construct(
        public readonly SomeInterface $dep,
    ) {}
}

class ServiceWithScalar
{
    public function __construct(
        public readonly string $name,
    ) {}
}

final class ContainerTest extends TestCase
{
    public function test_resolves_class_with_no_dependencies(): void
    {
        $container = new Container();

        $result = $container->get(SimpleService::class);

        $this->assertInstanceOf(SimpleService::class, $result);
    }

    public function test_autowires_constructor_dependencies(): void
    {
        $container = new Container();

        $result = $container->get(ServiceWithDependency::class);

        $this->assertInstanceOf(ServiceWithDependency::class, $result);
        $this->assertInstanceOf(SimpleService::class, $result->simple);
    }

    public function test_autowires_nested_dependencies(): void
    {
        $container = new Container();

        $result = $container->get(ServiceWithNestedDeps::class);

        $this->assertInstanceOf(ServiceWithNestedDeps::class, $result);
        $this->assertInstanceOf(ServiceWithDependency::class, $result->service);
        $this->assertInstanceOf(SimpleService::class, $result->simple);
    }

    public function test_has_returns_true_for_resolvable_class(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(SimpleService::class));
    }

    public function test_has_returns_false_for_unknown_string(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('nonexistent'));
    }

    public function test_throws_on_unresolvable_interface(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get(SomeInterface::class);
    }

    public function test_throws_on_unresolvable_scalar_param(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $container->get(ServiceWithScalar::class);
    }

    public function test_returns_new_instance_each_time_by_default(): void
    {
        $container = new Container();

        $a = $container->get(SimpleService::class);
        $b = $container->get(SimpleService::class);

        $this->assertNotSame($a, $b);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /Users/smyr/Sites/gbits/flopp
./vendor/bin/phpunit packages/core/tests/Container/ContainerTest.php
```

Expected: FAIL — classes do not exist yet.

- [ ] **Step 3: Write PSR-11 exception implementations**

Create `packages/core/src/Container/Exceptions/ContainerException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Exceptions;

use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends \RuntimeException implements ContainerExceptionInterface
{
}
```

Create `packages/core/src/Container/Exceptions/NotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
```

- [ ] **Step 4: Write Container implementation**

Create `packages/core/src/Container/Container.php`:

```php
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
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Container/ContainerTest.php
```

Expected: All 8 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Container packages/core/tests/Container/ContainerTest.php
git commit -m "feat(core): add DI container with PSR-11 compliance and autowiring"
```

---

### Task 3: Container — Bindings, Singletons, Instances

**Files:**
- Modify: `packages/core/tests/Container/ContainerTest.php`

- [ ] **Step 1: Add tests for bind, singleton, and instance**

Append to `packages/core/tests/Container/ContainerTest.php`:

```php
class ConcreteImplementation implements SomeInterface
{
    public function __construct(
        public readonly string $value = 'default',
    ) {}
}

// ... add these test methods to ContainerTest:

    public function test_bind_interface_to_class(): void
    {
        $container = new Container();
        $container->bind(SomeInterface::class, ConcreteImplementation::class);

        $result = $container->get(SomeInterface::class);

        $this->assertInstanceOf(ConcreteImplementation::class, $result);
    }

    public function test_bind_with_closure(): void
    {
        $container = new Container();
        $container->bind(SomeInterface::class, fn () => new ConcreteImplementation('custom'));

        $result = $container->get(SomeInterface::class);

        $this->assertInstanceOf(ConcreteImplementation::class, $result);
        $this->assertSame('custom', $result->value);
    }

    public function test_singleton_returns_same_instance(): void
    {
        $container = new Container();
        $container->singleton(SimpleService::class);

        $a = $container->get(SimpleService::class);
        $b = $container->get(SimpleService::class);

        $this->assertSame($a, $b);
    }

    public function test_singleton_with_binding(): void
    {
        $container = new Container();
        $container->singleton(SomeInterface::class, ConcreteImplementation::class);

        $a = $container->get(SomeInterface::class);
        $b = $container->get(SomeInterface::class);

        $this->assertInstanceOf(ConcreteImplementation::class, $a);
        $this->assertSame($a, $b);
    }

    public function test_instance_returns_exact_object(): void
    {
        $container = new Container();
        $obj = new SimpleService();
        $container->instance(SimpleService::class, $obj);

        $result = $container->get(SimpleService::class);

        $this->assertSame($obj, $result);
    }

    public function test_make_with_parameter_overrides(): void
    {
        $container = new Container();

        $result = $container->make(ServiceWithScalar::class, ['name' => 'hello']);

        $this->assertInstanceOf(ServiceWithScalar::class, $result);
        $this->assertSame('hello', $result->name);
    }

    public function test_detects_circular_dependency(): void
    {
        $container = new Container();
        $container->bind('a', fn ($c) => $c->get('b'));
        $container->bind('b', fn ($c) => $c->get('a'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');
        $container->get('a');
    }

    public function test_resolves_service_with_interface_after_binding(): void
    {
        $container = new Container();
        $container->bind(SomeInterface::class, ConcreteImplementation::class);

        $result = $container->get(ServiceWithInterface::class);

        $this->assertInstanceOf(ServiceWithInterface::class, $result);
        $this->assertInstanceOf(ConcreteImplementation::class, $result->dep);
    }
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Container/ContainerTest.php
```

Expected: All 16 tests pass. The implementation from Task 2 already supports bindings, singletons, instances, make, and circular dependency detection.

- [ ] **Step 3: Commit**

```bash
git add packages/core/tests/Container/ContainerTest.php
git commit -m "test(core): add binding, singleton, instance, and circular dependency tests"
```

---

### Task 4: Container — Attribute-Based Injection

**Files:**
- Create: `packages/core/src/Container/Attributes/Config.php`
- Create: `packages/core/src/Container/Attributes/Env.php`
- Modify: `packages/core/src/Container/Container.php`
- Create: `packages/core/tests/Container/AttributeInjectionTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/Container/AttributeInjectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\Attributes\Config;
use Preflow\Core\Container\Attributes\Env;
use Preflow\Core\Config\Config as ConfigStore;

class ServiceWithConfig
{
    public function __construct(
        #[Config('app.name')] public readonly string $appName,
        #[Config('app.debug')] public readonly bool $debug,
        #[Config('app.missing', 'fallback')] public readonly string $withDefault,
    ) {}
}

class ServiceWithEnv
{
    public function __construct(
        #[Env('APP_KEY')] public readonly string $key,
        #[Env('MISSING_VAR', 'default-val')] public readonly string $withDefault,
    ) {}
}

class ServiceWithMixed
{
    public function __construct(
        public readonly SimpleService $simple,
        #[Config('app.name')] public readonly string $name,
        #[Env('APP_KEY')] public readonly string $key,
    ) {}
}

final class AttributeInjectionTest extends TestCase
{
    public function test_injects_config_values(): void
    {
        $container = new Container();
        $config = new ConfigStore([
            'app' => [
                'name' => 'Preflow',
                'debug' => true,
            ],
        ]);
        $container->instance(ConfigStore::class, $config);

        $result = $container->get(ServiceWithConfig::class);

        $this->assertSame('Preflow', $result->appName);
        $this->assertTrue($result->debug);
        $this->assertSame('fallback', $result->withDefault);
    }

    public function test_injects_env_values(): void
    {
        putenv('APP_KEY=secret123');

        $container = new Container();

        $result = $container->get(ServiceWithEnv::class);

        $this->assertSame('secret123', $result->key);
        $this->assertSame('default-val', $result->withDefault);

        putenv('APP_KEY'); // cleanup
    }

    public function test_mixes_autowiring_with_attributes(): void
    {
        putenv('APP_KEY=mixed-key');

        $container = new Container();
        $config = new ConfigStore(['app' => ['name' => 'TestApp']]);
        $container->instance(ConfigStore::class, $config);

        $result = $container->get(ServiceWithMixed::class);

        $this->assertInstanceOf(SimpleService::class, $result->simple);
        $this->assertSame('TestApp', $result->name);
        $this->assertSame('mixed-key', $result->key);

        putenv('APP_KEY');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/core/tests/Container/AttributeInjectionTest.php
```

Expected: FAIL — Config and Env attributes do not exist yet.

- [ ] **Step 3: Create the Config attribute**

Create `packages/core/src/Container/Attributes/Config.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Config
{
    public function __construct(
        public string $key,
        public mixed $default = null,
    ) {}
}
```

- [ ] **Step 4: Create the Env attribute**

Create `packages/core/src/Container/Attributes/Env.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Attributes;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class Env
{
    public function __construct(
        public string $name,
        public ?string $default = null,
    ) {}
}
```

- [ ] **Step 5: Create the Config store (needed by attribute resolution)**

Create `packages/core/src/Config/Config.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Config;

final class Config
{
    /** @var array<string, mixed> */
    private array $items;

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public function has(string $key): bool
    {
        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &$this->items;

        foreach (array_slice($segments, 0, -1) as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current[end($segments)] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }
}
```

- [ ] **Step 6: Update Container to resolve attributes**

In `packages/core/src/Container/Container.php`, replace the `resolveParameter` method:

```php
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
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Container/
```

Expected: All tests pass (ContainerTest + AttributeInjectionTest).

- [ ] **Step 8: Commit**

```bash
git add packages/core/src/Container/Attributes packages/core/src/Config packages/core/tests/Container/AttributeInjectionTest.php packages/core/src/Container/Container.php
git commit -m "feat(core): add #[Config] and #[Env] attribute injection to container"
```

---

### Task 5: Config Store Tests

**Files:**
- Create: `packages/core/tests/Config/ConfigTest.php`

- [ ] **Step 1: Write tests for the Config class**

Create `packages/core/tests/Config/ConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Config;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Config\Config;

final class ConfigTest extends TestCase
{
    public function test_get_top_level_key(): void
    {
        $config = new Config(['name' => 'Preflow']);

        $this->assertSame('Preflow', $config->get('name'));
    }

    public function test_get_nested_key_with_dot_notation(): void
    {
        $config = new Config([
            'app' => [
                'name' => 'Preflow',
                'nested' => ['deep' => 'value'],
            ],
        ]);

        $this->assertSame('Preflow', $config->get('app.name'));
        $this->assertSame('value', $config->get('app.nested.deep'));
    }

    public function test_get_returns_default_for_missing_key(): void
    {
        $config = new Config([]);

        $this->assertNull($config->get('missing'));
        $this->assertSame('fallback', $config->get('missing', 'fallback'));
    }

    public function test_get_returns_default_for_partially_missing_path(): void
    {
        $config = new Config(['app' => ['name' => 'Preflow']]);

        $this->assertSame('nope', $config->get('app.nonexistent.deep', 'nope'));
    }

    public function test_has_returns_true_for_existing_key(): void
    {
        $config = new Config(['app' => ['name' => 'Preflow']]);

        $this->assertTrue($config->has('app'));
        $this->assertTrue($config->has('app.name'));
    }

    public function test_has_returns_false_for_missing_key(): void
    {
        $config = new Config(['app' => ['name' => 'Preflow']]);

        $this->assertFalse($config->has('missing'));
        $this->assertFalse($config->has('app.missing'));
    }

    public function test_set_creates_nested_keys(): void
    {
        $config = new Config([]);

        $config->set('app.database.host', 'localhost');

        $this->assertSame('localhost', $config->get('app.database.host'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $config = new Config(['app' => ['name' => 'Old']]);

        $config->set('app.name', 'New');

        $this->assertSame('New', $config->get('app.name'));
    }

    public function test_all_returns_full_array(): void
    {
        $items = ['app' => ['name' => 'Preflow']];
        $config = new Config($items);

        $this->assertSame($items, $config->all());
    }

    public function test_get_returns_array_value(): void
    {
        $config = new Config([
            'app' => ['allowed' => ['a', 'b', 'c']],
        ]);

        $this->assertSame(['a', 'b', 'c'], $config->get('app.allowed'));
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Config/ConfigTest.php
```

Expected: All 10 tests pass (Config was already implemented in Task 4).

- [ ] **Step 3: Commit**

```bash
git add packages/core/tests/Config/ConfigTest.php
git commit -m "test(core): add Config store tests with dot notation, set, has"
```

---

### Task 6: Service Providers

**Files:**
- Create: `packages/core/src/Container/ServiceProvider.php`
- Create: `packages/core/tests/Container/ServiceProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/Container/ServiceProviderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Container;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

interface LoggerInterface
{
    public function log(string $message): void;
}

class FileLogger implements LoggerInterface
{
    public function log(string $message): void {}
}

class TestProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->bind(LoggerInterface::class, FileLogger::class);
        $container->singleton(FileLogger::class);
    }

    public function boot(Container $container): void
    {
        // boot phase — services are available here
    }
}

class ProviderWithBoot extends ServiceProvider
{
    public bool $booted = false;

    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $this->booted = true;
    }
}

final class ServiceProviderTest extends TestCase
{
    public function test_provider_registers_bindings(): void
    {
        $container = new Container();
        $provider = new TestProvider();

        $provider->register($container);

        $result = $container->get(LoggerInterface::class);
        $this->assertInstanceOf(FileLogger::class, $result);
    }

    public function test_provider_singleton_works(): void
    {
        $container = new Container();
        $provider = new TestProvider();

        $provider->register($container);

        $a = $container->get(FileLogger::class);
        $b = $container->get(FileLogger::class);
        $this->assertSame($a, $b);
    }

    public function test_boot_is_called(): void
    {
        $container = new Container();
        $provider = new ProviderWithBoot();

        $provider->register($container);
        $provider->boot($container);

        $this->assertTrue($provider->booted);
    }

    public function test_container_registers_and_boots_providers(): void
    {
        $container = new Container();
        $provider = new ProviderWithBoot();

        $container->registerProvider($provider);
        $container->bootProviders();

        $this->assertTrue($provider->booted);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/core/tests/Container/ServiceProviderTest.php
```

Expected: FAIL — `ServiceProvider` class and `registerProvider`/`bootProviders` do not exist.

- [ ] **Step 3: Create ServiceProvider base class**

Create `packages/core/src/Container/ServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Container;

abstract class ServiceProvider
{
    abstract public function register(Container $container): void;

    public function boot(Container $container): void
    {
        // Override in subclass if needed
    }
}
```

- [ ] **Step 4: Add provider registration to Container**

Add these methods to `packages/core/src/Container/Container.php`:

```php
    /** @var ServiceProvider[] */
    private array $providers = [];

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
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Container/ServiceProviderTest.php
```

Expected: All 4 tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Container/ServiceProvider.php packages/core/src/Container/Container.php packages/core/tests/Container/ServiceProviderTest.php
git commit -m "feat(core): add ServiceProvider base class with register/boot lifecycle"
```

---

### Task 7: Middleware Pipeline

**Files:**
- Create: `packages/core/src/Http/MiddlewarePipeline.php`
- Create: `packages/core/tests/Http/MiddlewarePipelineTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/Http/MiddlewarePipelineTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Http\MiddlewarePipeline;

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $response = $handler->handle($request);
        return $response->withHeader($this->name, $this->value);
    }
}

class ModifyRequestMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $request = $request->withAttribute('modified', true);
        return $handler->handle($request);
    }
}

class ShortCircuitMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        return new Response(403, [], 'Forbidden');
    }
}

final class MiddlewarePipelineTest extends TestCase
{
    private function createRequest(string $method = 'GET', string $uri = '/'): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return $factory->createServerRequest($method, $uri);
    }

    public function test_empty_pipeline_calls_core_handler(): void
    {
        $pipeline = new MiddlewarePipeline();
        $request = $this->createRequest();

        $response = $pipeline->process($request, function (ServerRequestInterface $req): ResponseInterface {
            return new Response(200, [], 'OK');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
    }

    public function test_middleware_can_modify_response(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new AddHeaderMiddleware('X-Custom', 'test-value'));

        $response = $pipeline->process($this->createRequest(), function ($req) {
            return new Response(200);
        });

        $this->assertSame('test-value', $response->getHeaderLine('X-Custom'));
    }

    public function test_middleware_executes_in_order(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new AddHeaderMiddleware('X-First', '1'));
        $pipeline->pipe(new AddHeaderMiddleware('X-Second', '2'));

        $response = $pipeline->process($this->createRequest(), function ($req) {
            return new Response(200);
        });

        $this->assertSame('1', $response->getHeaderLine('X-First'));
        $this->assertSame('2', $response->getHeaderLine('X-Second'));
    }

    public function test_middleware_can_modify_request(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new ModifyRequestMiddleware());

        $capturedRequest = null;
        $pipeline->process($this->createRequest(), function (ServerRequestInterface $req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return new Response(200);
        });

        $this->assertTrue($capturedRequest->getAttribute('modified'));
    }

    public function test_middleware_can_short_circuit(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new ShortCircuitMiddleware());
        $pipeline->pipe(new AddHeaderMiddleware('X-After', 'should-not-appear'));

        $coreReached = false;
        $response = $pipeline->process($this->createRequest(), function ($req) use (&$coreReached) {
            $coreReached = true;
            return new Response(200);
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($coreReached);
        $this->assertFalse($response->hasHeader('X-After'));
    }

    public function test_multiple_middleware_stack(): void
    {
        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new AddHeaderMiddleware('X-Outer', 'outer'));
        $pipeline->pipe(new ModifyRequestMiddleware());
        $pipeline->pipe(new AddHeaderMiddleware('X-Inner', 'inner'));

        $capturedRequest = null;
        $response = $pipeline->process($this->createRequest(), function (ServerRequestInterface $req) use (&$capturedRequest) {
            $capturedRequest = $req;
            return new Response(200);
        });

        $this->assertSame('outer', $response->getHeaderLine('X-Outer'));
        $this->assertSame('inner', $response->getHeaderLine('X-Inner'));
        $this->assertTrue($capturedRequest->getAttribute('modified'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/core/tests/Http/MiddlewarePipelineTest.php
```

Expected: FAIL — `MiddlewarePipeline` does not exist.

- [ ] **Step 3: Implement MiddlewarePipeline**

Create `packages/core/src/Http/MiddlewarePipeline.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MiddlewarePipeline
{
    /** @var MiddlewareInterface[] */
    private array $middleware = [];

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * Process the request through the middleware stack.
     *
     * @param callable(ServerRequestInterface): ResponseInterface $coreHandler
     */
    public function process(ServerRequestInterface $request, callable $coreHandler): ResponseInterface
    {
        $handler = new class($coreHandler) implements RequestHandlerInterface {
            /** @var callable(ServerRequestInterface): ResponseInterface */
            private $callback;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->callback)($request);
            }
        };

        // Build the handler chain from inside out
        $stack = $handler;
        foreach (array_reverse($this->middleware) as $mw) {
            $stack = new class($mw, $stack) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $stack->handle($request);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Http/MiddlewarePipelineTest.php
```

Expected: All 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Http/MiddlewarePipeline.php packages/core/tests/Http/MiddlewarePipelineTest.php
git commit -m "feat(core): add PSR-15 middleware pipeline"
```

---

### Task 8: HTTP Exceptions

**Files:**
- Create: `packages/core/src/Exceptions/HttpException.php`
- Create: `packages/core/src/Exceptions/NotFoundHttpException.php`
- Create: `packages/core/src/Exceptions/ForbiddenHttpException.php`
- Create: `packages/core/src/Exceptions/UnauthorizedHttpException.php`
- Create: `packages/core/src/Exceptions/SecurityException.php`

- [ ] **Step 1: Create the exception classes**

Create `packages/core/src/Exceptions/HttpException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
```

Create `packages/core/src/Exceptions/NotFoundHttpException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class NotFoundHttpException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $previous);
    }
}
```

Create `packages/core/src/Exceptions/ForbiddenHttpException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class ForbiddenHttpException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}
```

Create `packages/core/src/Exceptions/UnauthorizedHttpException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class UnauthorizedHttpException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, $previous);
    }
}
```

Create `packages/core/src/Exceptions/SecurityException.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Exceptions;

final class SecurityException extends ForbiddenHttpException
{
    public function __construct(string $message = 'Security violation', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/core/src/Exceptions/
git commit -m "feat(core): add HTTP exception hierarchy"
```

---

### Task 9: Error Handler

**Files:**
- Create: `packages/core/src/Error/ErrorHandler.php`
- Create: `packages/core/src/Error/ErrorRendererInterface.php`
- Create: `packages/core/src/Error/DevErrorRenderer.php`
- Create: `packages/core/src/Error/ProdErrorRenderer.php`
- Create: `packages/core/tests/Error/ErrorHandlerTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/Error/ErrorHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Error;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Exceptions\NotFoundHttpException;

final class ErrorHandlerTest extends TestCase
{
    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/test');
    }

    public function test_handles_http_exception_with_correct_status(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());

        $response = $handler->handleException(
            new NotFoundHttpException('Page not found'),
            $this->createRequest(),
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_handles_generic_exception_as_500(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());

        $response = $handler->handleException(
            new \RuntimeException('Something broke'),
            $this->createRequest(),
        );

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_prod_renderer_hides_details(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());

        $response = $handler->handleException(
            new \RuntimeException('secret internal details'),
            $this->createRequest(),
        );

        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('secret internal details', $body);
        $this->assertStringContainsString('Internal Server Error', $body);
    }

    public function test_dev_renderer_shows_details(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer());

        $response = $handler->handleException(
            new \RuntimeException('visible debug info'),
            $this->createRequest(),
        );

        $body = (string) $response->getBody();
        $this->assertStringContainsString('visible debug info', $body);
        $this->assertStringContainsString('RuntimeException', $body);
    }

    public function test_dev_renderer_shows_stack_trace(): void
    {
        $handler = new ErrorHandler(new DevErrorRenderer());

        $response = $handler->handleException(
            new \RuntimeException('trace test'),
            $this->createRequest(),
        );

        $body = (string) $response->getBody();
        $this->assertStringContainsString('ErrorHandlerTest.php', $body);
    }

    public function test_http_exception_message_shown_in_prod(): void
    {
        $handler = new ErrorHandler(new ProdErrorRenderer());

        $response = $handler->handleException(
            new NotFoundHttpException('The page you requested was not found'),
            $this->createRequest(),
        );

        $body = (string) $response->getBody();
        $this->assertStringContainsString('The page you requested was not found', $body);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/core/tests/Error/ErrorHandlerTest.php
```

Expected: FAIL — classes do not exist.

- [ ] **Step 3: Create ErrorRendererInterface**

Create `packages/core/src/Error/ErrorRendererInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Psr\Http\Message\ServerRequestInterface;

interface ErrorRendererInterface
{
    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string;
}
```

- [ ] **Step 4: Create ProdErrorRenderer**

Create `packages/core/src/Error/ProdErrorRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\HttpException;

final class ProdErrorRenderer implements ErrorRendererInterface
{
    private const STATUS_MESSAGES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        // For HTTP exceptions, show their message (it's user-facing by design)
        if ($exception instanceof HttpException) {
            $title = self::STATUS_MESSAGES[$statusCode] ?? 'Error';
            $message = $exception->getMessage();
        } else {
            // For unexpected exceptions, hide details
            $title = self::STATUS_MESSAGES[$statusCode] ?? 'Internal Server Error';
            $message = $title;
        }

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$statusCode} — {$title}</title>
            <style>
                body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #f8f9fa; color: #333; }
                .error { text-align: center; }
                .code { font-size: 4rem; font-weight: 700; color: #dee2e6; }
                .message { margin-top: 1rem; font-size: 1.125rem; }
            </style>
        </head>
        <body>
            <div class="error">
                <div class="code">{$statusCode}</div>
                <div class="message">{$this->escape($message)}</div>
            </div>
        </body>
        </html>
        HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 5: Create DevErrorRenderer**

Create `packages/core/src/Error/DevErrorRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Psr\Http\Message\ServerRequestInterface;

final class DevErrorRenderer implements ErrorRendererInterface
{
    public function render(\Throwable $exception, ServerRequestInterface $request, int $statusCode): string
    {
        $class = $exception::class;
        $message = $this->escape($exception->getMessage());
        $file = $this->escape($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->escape($exception->getTraceAsString());
        $method = $this->escape($request->getMethod());
        $uri = $this->escape((string) $request->getUri());

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$statusCode} — {$this->escape($class)}</title>
            <style>
                * { box-sizing: border-box; }
                body { font-family: system-ui, sans-serif; margin: 0; padding: 2rem; background: #1a1a2e; color: #eee; }
                .header { background: #e74c3c; padding: 1.5rem 2rem; border-radius: 0.5rem; margin-bottom: 1.5rem; }
                .header h1 { margin: 0; font-size: 1.25rem; font-weight: 600; }
                .header .message { margin-top: 0.5rem; font-size: 1rem; opacity: 0.9; }
                .section { background: #16213e; padding: 1.5rem; border-radius: 0.5rem; margin-bottom: 1rem; }
                .section h2 { margin: 0 0 1rem; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; color: #888; }
                .meta { display: grid; grid-template-columns: auto 1fr; gap: 0.25rem 1rem; font-size: 0.875rem; }
                .meta dt { color: #888; }
                .meta dd { margin: 0; font-family: monospace; }
                pre { margin: 0; font-size: 0.8125rem; line-height: 1.6; overflow-x: auto; white-space: pre-wrap; word-break: break-all; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>{$this->escape($class)}</h1>
                <div class="message">{$message}</div>
            </div>

            <div class="section">
                <h2>Request</h2>
                <dl class="meta">
                    <dt>Method</dt><dd>{$method}</dd>
                    <dt>URI</dt><dd>{$uri}</dd>
                </dl>
            </div>

            <div class="section">
                <h2>Location</h2>
                <dl class="meta">
                    <dt>File</dt><dd>{$file}:{$line}</dd>
                </dl>
            </div>

            <div class="section">
                <h2>Stack Trace</h2>
                <pre>{$trace}</pre>
            </div>
        </body>
        </html>
        HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 6: Create ErrorHandler**

Create `packages/core/src/Error/ErrorHandler.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Error;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\HttpException;

final class ErrorHandler
{
    public function __construct(
        private readonly ErrorRendererInterface $renderer,
    ) {}

    public function handleException(\Throwable $exception, ServerRequestInterface $request): ResponseInterface
    {
        $statusCode = $exception instanceof HttpException
            ? $exception->statusCode
            : 500;

        $body = $this->renderer->render($exception, $request, $statusCode);

        return new Response($statusCode, ['Content-Type' => 'text/html; charset=UTF-8'], $body);
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/Error/ErrorHandlerTest.php
```

Expected: All 6 tests pass.

- [ ] **Step 8: Commit**

```bash
git add packages/core/src/Error packages/core/tests/Error
git commit -m "feat(core): add error handler with dev/prod renderers"
```

---

### Task 10: Route Contracts (Kernel Dependencies)

**Files:**
- Create: `packages/core/src/Routing/RouteMode.php`
- Create: `packages/core/src/Routing/Route.php`
- Create: `packages/core/src/Routing/RouterInterface.php`

- [ ] **Step 1: Create RouteMode enum**

Create `packages/core/src/Routing/RouteMode.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

enum RouteMode: string
{
    case Component = 'component';
    case Action = 'action';
}
```

- [ ] **Step 2: Create Route value object**

Create `packages/core/src/Routing/Route.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

final readonly class Route
{
    /**
     * @param array<string, string> $parameters  Extracted route parameters
     * @param string[]              $middleware   Middleware class names for this route
     */
    public function __construct(
        public RouteMode $mode,
        public string $handler,
        public array $parameters = [],
        public array $middleware = [],
        public ?string $action = null,
    ) {}
}
```

- [ ] **Step 3: Create RouterInterface**

Create `packages/core/src/Routing/RouterInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface
{
    /**
     * Match a request to a route.
     *
     * @throws \Preflow\Core\Exceptions\NotFoundHttpException If no route matches
     */
    public function match(ServerRequestInterface $request): Route;
}
```

- [ ] **Step 4: Commit**

```bash
git add packages/core/src/Routing/
git commit -m "feat(core): add Route value object, RouteMode enum, and RouterInterface"
```

---

### Task 11: Kernel

**Files:**
- Create: `packages/core/src/Kernel.php`
- Create: `packages/core/tests/KernelTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/KernelTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\Core\Container\Container;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Kernel;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;

class StubRouter implements RouterInterface
{
    public function __construct(
        private readonly ?Route $route = null,
    ) {}

    public function match(ServerRequestInterface $request): Route
    {
        if ($this->route === null) {
            throw new NotFoundHttpException();
        }
        return $this->route;
    }
}

class StubActionHandler
{
    public function handle(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'action:' . $route->handler);
    }
}

class StubComponentRenderer
{
    public function render(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'component:' . $route->handler);
    }
}

class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        return $response->withHeader('X-Timing', 'applied');
    }
}

final class KernelTest extends TestCase
{
    private function createRequest(string $method = 'GET', string $uri = '/'): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest($method, $uri);
    }

    private function createKernel(
        ?RouterInterface $router = null,
        array $middleware = [],
    ): Kernel {
        $container = new Container();

        $actionHandler = new StubActionHandler();
        $componentRenderer = new StubComponentRenderer();
        $container->instance(StubActionHandler::class, $actionHandler);
        $container->instance(StubComponentRenderer::class, $componentRenderer);

        $pipeline = new MiddlewarePipeline();
        foreach ($middleware as $mw) {
            $pipeline->pipe($mw);
        }

        $errorHandler = new ErrorHandler(new DevErrorRenderer());

        return new Kernel(
            container: $container,
            router: $router ?? new StubRouter(),
            pipeline: $pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: fn (Route $r, ServerRequestInterface $req) => $actionHandler->handle($r, $req),
            componentRenderer: fn (Route $r, ServerRequestInterface $req) => $componentRenderer->render($r, $req),
        );
    }

    public function test_dispatches_action_mode(): void
    {
        $route = new Route(
            mode: RouteMode::Action,
            handler: 'TestController@index',
        );

        $kernel = $this->createKernel(new StubRouter($route));
        $response = $kernel->handle($this->createRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('action:TestController@index', (string) $response->getBody());
    }

    public function test_dispatches_component_mode(): void
    {
        $route = new Route(
            mode: RouteMode::Component,
            handler: 'pages/index',
        );

        $kernel = $this->createKernel(new StubRouter($route));
        $response = $kernel->handle($this->createRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('component:pages/index', (string) $response->getBody());
    }

    public function test_middleware_is_applied(): void
    {
        $route = new Route(mode: RouteMode::Action, handler: 'test');

        $kernel = $this->createKernel(
            router: new StubRouter($route),
            middleware: [new TimingMiddleware()],
        );

        $response = $kernel->handle($this->createRequest());

        $this->assertSame('applied', $response->getHeaderLine('X-Timing'));
    }

    public function test_not_found_returns_404(): void
    {
        $kernel = $this->createKernel(new StubRouter(null));

        $response = $kernel->handle($this->createRequest());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_unexpected_error_returns_500(): void
    {
        $router = new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                throw new \RuntimeException('Unexpected failure');
            }
        };

        $kernel = $this->createKernel($router);
        $response = $kernel->handle($this->createRequest());

        $this->assertSame(500, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/core/tests/KernelTest.php
```

Expected: FAIL — `Kernel` class does not exist.

- [ ] **Step 3: Implement the Kernel**

Create `packages/core/src/Kernel.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Container\Container;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;

final class Kernel
{
    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $actionDispatcher;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $componentRenderer;

    /**
     * @param callable(Route, ServerRequestInterface): ResponseInterface $actionDispatcher
     * @param callable(Route, ServerRequestInterface): ResponseInterface $componentRenderer
     */
    public function __construct(
        private readonly Container $container,
        private readonly RouterInterface $router,
        private readonly MiddlewarePipeline $pipeline,
        private readonly ErrorHandler $errorHandler,
        callable $actionDispatcher,
        callable $componentRenderer,
    ) {
        $this->actionDispatcher = $actionDispatcher;
        $this->componentRenderer = $componentRenderer;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->pipeline->process($request, function (ServerRequestInterface $req): ResponseInterface {
                $route = $this->router->match($req);

                return match ($route->mode) {
                    RouteMode::Component => ($this->componentRenderer)($route, $req),
                    RouteMode::Action => ($this->actionDispatcher)($route, $req),
                };
            });
        } catch (\Throwable $e) {
            return $this->errorHandler->handleException($e, $request);
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/KernelTest.php
```

Expected: All 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/Kernel.php packages/core/tests/KernelTest.php
git commit -m "feat(core): add dual-mode Kernel with middleware pipeline and error handling"
```

---

### Task 12: Response Emitter

**Files:**
- Create: `packages/core/src/Http/Emitter.php`

- [ ] **Step 1: Create the response emitter**

Create `packages/core/src/Http/Emitter.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;

final class Emitter
{
    public function emit(ResponseInterface $response): void
    {
        if (headers_sent()) {
            return;
        }

        // Status line
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();
        $protocol = $response->getProtocolVersion();

        header(
            "HTTP/{$protocol} {$statusCode} {$reasonPhrase}",
            true,
            $statusCode,
        );

        // Headers
        foreach ($response->getHeaders() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                header("{$name}: {$value}", $first);
                $first = false;
            }
        }

        // Body
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$body->eof()) {
            echo $body->read(8192);

            if (connection_aborted()) {
                break;
            }
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add packages/core/src/Http/Emitter.php
git commit -m "feat(core): add PSR-7 response emitter"
```

---

### Task 13: Application Bootstrap

**Files:**
- Create: `packages/core/src/Application.php`
- Create: `packages/core/tests/ApplicationTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/ApplicationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Config\Config;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Core\Exceptions\NotFoundHttpException;

class AppTestRouter implements RouterInterface
{
    public function match(ServerRequestInterface $request): Route
    {
        return match ($request->getUri()->getPath()) {
            '/' => new Route(RouteMode::Action, handler: 'home'),
            default => throw new NotFoundHttpException(),
        };
    }
}

class AppTestProvider extends ServiceProvider
{
    public bool $registered = false;
    public bool $booted = false;

    public function register(Container $container): void
    {
        $this->registered = true;
    }

    public function boot(Container $container): void
    {
        $this->booted = true;
    }
}

final class ApplicationTest extends TestCase
{
    public function test_creates_with_config(): void
    {
        $app = Application::create([
            'app' => ['name' => 'TestApp', 'debug' => true],
        ]);

        $this->assertInstanceOf(Application::class, $app);
        $this->assertSame('TestApp', $app->config()->get('app.name'));
    }

    public function test_container_is_accessible(): void
    {
        $app = Application::create([]);

        $this->assertInstanceOf(Container::class, $app->container());
    }

    public function test_registers_and_boots_providers(): void
    {
        $provider = new AppTestProvider();

        $app = Application::create([]);
        $app->registerProvider($provider);
        $app->boot();

        $this->assertTrue($provider->registered);
        $this->assertTrue($provider->booted);
    }

    public function test_handles_request(): void
    {
        $app = Application::create([
            'app' => ['debug' => true],
        ]);

        $app->setRouter(new AppTestRouter());
        $app->setActionDispatcher(fn ($route, $req) => new Response(200, [], 'home'));
        $app->setComponentRenderer(fn ($route, $req) => new Response(200, [], 'component'));
        $app->boot();

        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('home', (string) $response->getBody());
    }

    public function test_handles_404(): void
    {
        $app = Application::create([
            'app' => ['debug' => false],
        ]);

        $app->setRouter(new AppTestRouter());
        $app->setActionDispatcher(fn ($route, $req) => new Response(200));
        $app->setComponentRenderer(fn ($route, $req) => new Response(200));
        $app->boot();

        $request = (new Psr17Factory())->createServerRequest('GET', '/nonexistent');
        $response = $app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function test_config_is_available_in_container(): void
    {
        $app = Application::create([
            'app' => ['name' => 'ContainerTest'],
        ]);

        $config = $app->container()->get(Config::class);

        $this->assertSame('ContainerTest', $config->get('app.name'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/core/tests/ApplicationTest.php
```

Expected: FAIL — `Application` class does not exist.

- [ ] **Step 3: Implement Application**

Create `packages/core/src/Application.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Config\Config;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Error\DevErrorRenderer;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;

final class Application
{
    private readonly Container $container;
    private readonly Config $config;
    private readonly MiddlewarePipeline $pipeline;
    private ?RouterInterface $router = null;
    private ?Kernel $kernel = null;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $actionDispatcher;

    /** @var callable(Route, ServerRequestInterface): ResponseInterface */
    private $componentRenderer;

    private function __construct(Config $config)
    {
        $this->config = $config;
        $this->container = new Container();
        $this->pipeline = new MiddlewarePipeline();

        // Register core instances
        $this->container->instance(self::class, $this);
        $this->container->instance(Config::class, $config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(MiddlewarePipeline::class, $this->pipeline);
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): self
    {
        return new self(new Config($config));
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function registerProvider(ServiceProvider $provider): void
    {
        $this->container->registerProvider($provider);
    }

    public function setRouter(RouterInterface $router): void
    {
        $this->router = $router;
        $this->container->instance(RouterInterface::class, $router);
    }

    /**
     * @param callable(Route, ServerRequestInterface): ResponseInterface $dispatcher
     */
    public function setActionDispatcher(callable $dispatcher): void
    {
        $this->actionDispatcher = $dispatcher;
    }

    /**
     * @param callable(Route, ServerRequestInterface): ResponseInterface $renderer
     */
    public function setComponentRenderer(callable $renderer): void
    {
        $this->componentRenderer = $renderer;
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware): void
    {
        $this->pipeline->pipe($middleware);
    }

    public function boot(): void
    {
        $this->container->bootProviders();

        $debug = (bool) $this->config->get('app.debug', false);
        $renderer = $debug ? new DevErrorRenderer() : new ProdErrorRenderer();
        $errorHandler = new ErrorHandler($renderer);

        $this->container->instance(ErrorHandler::class, $errorHandler);

        $this->kernel = new Kernel(
            container: $this->container,
            router: $this->router ?? throw new \RuntimeException('No router configured'),
            pipeline: $this->pipeline,
            errorHandler: $errorHandler,
            actionDispatcher: $this->actionDispatcher ?? throw new \RuntimeException('No action dispatcher configured'),
            componentRenderer: $this->componentRenderer ?? throw new \RuntimeException('No component renderer configured'),
        );
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->kernel === null) {
            throw new \RuntimeException('Application not booted. Call boot() first.');
        }

        return $this->kernel->handle($request);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
./vendor/bin/phpunit packages/core/tests/ApplicationTest.php
```

Expected: All 5 tests pass.

- [ ] **Step 5: Run the full test suite**

```bash
./vendor/bin/phpunit
```

Expected: All tests across all test files pass (ContainerTest, AttributeInjectionTest, ServiceProviderTest, ConfigTest, MiddlewarePipelineTest, ErrorHandlerTest, KernelTest, ApplicationTest).

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Application.php packages/core/tests/ApplicationTest.php
git commit -m "feat(core): add Application bootstrap with create/boot/handle lifecycle"
```

---

### Task 14: Final Cleanup and Full Test Run

**Files:**
- No new files

- [ ] **Step 1: Run full test suite with coverage summary**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass. No errors, no warnings.

- [ ] **Step 2: Verify class autoloading works**

```bash
php -r "require 'vendor/autoload.php'; echo Preflow\Core\Routing\RouteMode::Component->value;"
```

Expected: Prints `component`.

- [ ] **Step 3: Commit final state**

If any cleanup was needed:

```bash
git add -A
git commit -m "chore(core): phase 1 complete — core package with container, config, middleware, error handling, kernel"
```

---

## Phase 1 Deliverables

After completing all tasks, the `preflow/core` package provides:

| Component | What It Does |
|---|---|
| `Container` | PSR-11 DI container with autowiring, #[Config], #[Env] attributes |
| `ServiceProvider` | Abstract base for registering bindings |
| `Config` | Array-based config with dot-notation access |
| `MiddlewarePipeline` | PSR-15 middleware stack |
| `ErrorHandler` | Exception → Response with dev/prod rendering |
| `Kernel` | Dual-mode request dispatch (Component/Action) |
| `Application` | Bootstrap, provider registration, request handling |
| `Route`, `RouteMode`, `RouterInterface` | Contracts for the routing package |
| `HttpException` hierarchy | Typed HTTP errors (404, 403, 401, 500) |

**Next phase:** `preflow/routing` — implements `RouterInterface` with file-based and attribute-based route discovery.
