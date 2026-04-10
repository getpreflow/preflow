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

class ConcreteImplementation implements SomeInterface
{
    public function __construct(
        public readonly string $value = 'default',
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
}
