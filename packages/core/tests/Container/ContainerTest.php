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
