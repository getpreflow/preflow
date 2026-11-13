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

#[\Preflow\Core\Container\Attributes\Singleton]
class SharedService
{
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

    public function test_resolved_says_whether_a_shared_instance_exists(): void
    {
        $container = new Container();
        $container->singleton(SomeInterface::class, ConcreteImplementation::class);
        $container->instance(SimpleService::class, new SimpleService());

        $this->assertTrue($container->resolved(SimpleService::class));
        $this->assertFalse($container->resolved(SomeInterface::class), 'a singleton not asked for yet');
        $container->get(SomeInterface::class);
        $this->assertTrue($container->resolved(SomeInterface::class));
        $this->assertFalse($container->resolved('Unbound\\Thing'));
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

    public function test_make_overrides_win_over_autowiring_and_build_a_new_instance(): void
    {
        $container = new Container();
        $shared = new ServiceWithDependency(new SimpleService());
        $container->instance(ServiceWithDependency::class, $shared);
        $given = new SimpleService();

        $made = $container->make(ServiceWithDependency::class, ['simple' => $given]);

        $this->assertNotSame($shared, $made);
        $this->assertSame($given, $made->simple);
        $this->assertSame($shared, $container->get(ServiceWithDependency::class));

        $mixed = $container->make(ServiceWithNestedDeps::class, ['simple' => $given]);
        $this->assertSame($given, $mixed->simple);
        $this->assertSame($shared, $mixed->service, 'the parameters not given are autowired');
    }

    public function test_make_passes_parameters_through_a_class_binding(): void
    {
        $container = new Container();
        $container->bind(SomeInterface::class, ConcreteImplementation::class);

        $made = $container->make(SomeInterface::class, ['value' => 'given']);

        $this->assertInstanceOf(ConcreteImplementation::class, $made);
        $this->assertSame('given', $made->value);
        $this->assertSame('default', $container->get(SomeInterface::class)->value, 'get() is unchanged');
    }

    public function test_make_passes_parameters_to_a_factory_that_takes_them(): void
    {
        $container = new Container();
        $container->bind(ServiceWithScalar::class, static fn (Container $c, array $parameters): ServiceWithScalar => new ServiceWithScalar($parameters['name'] ?? 'bound'));

        $this->assertSame('given', $container->make(ServiceWithScalar::class, ['name' => 'given'])->name);
        $this->assertSame('bound', $container->make(ServiceWithScalar::class)->name);
    }

    public function test_make_refuses_parameters_a_factory_would_drop(): void
    {
        $container = new Container();
        $container->bind(ServiceWithScalar::class, static fn (Container $c): ServiceWithScalar => new ServiceWithScalar('bound'));

        $this->assertSame('bound', $container->make(ServiceWithScalar::class)->name, 'without parameters as before');
        $this->expectExceptionObject(new ContainerException('[' . ServiceWithScalar::class . '] is bound to a factory that takes no parameters: it cannot be made with [name].'));
        $container->make(ServiceWithScalar::class, ['name' => 'given']);
    }

    public function test_make_refuses_parameters_for_an_instance_that_is_no_class(): void
    {
        $container = new Container();
        $container->instance('mailer', new SimpleService());

        $this->expectExceptionObject(new ContainerException('[mailer] is a registered instance, not a class: it cannot be made with parameters.'));
        $container->make('mailer', ['from' => 'x']);
    }

    public function test_make_with_a_wrongly_typed_override_fails(): void
    {
        $this->expectException(\TypeError::class);
        (new Container())->make(ServiceWithDependency::class, ['simple' => []]);
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

    public function test_make_with_parameters_detects_a_cycle_of_class_bindings(): void
    {
        $container = new Container();
        $container->bind(SomeInterface::class, ConcreteImplementation::class);
        $container->bind(ConcreteImplementation::class, SomeInterface::class);

        $this->expectExceptionObject(new ContainerException('Circular dependency detected while resolving [' . SomeInterface::class . '].'));
        $container->make(SomeInterface::class, ['value' => 'given']);
    }

    public function test_resolves_service_with_interface_after_binding(): void
    {
        $container = new Container();
        $container->bind(SomeInterface::class, ConcreteImplementation::class);

        $result = $container->get(ServiceWithInterface::class);

        $this->assertInstanceOf(ServiceWithInterface::class, $result);
        $this->assertInstanceOf(ConcreteImplementation::class, $result->dep);
    }

    public function test_bound_is_true_only_for_bindings_and_instances(): void
    {
        $container = new Container();
        $container->bind('a', fn () => new \stdClass());
        $container->instance('b', new \stdClass());

        $this->assertTrue($container->bound('a'));
        $this->assertTrue($container->bound('b'));
        $this->assertFalse($container->bound(\stdClass::class)); // has() would say true: autowirable
    }

    public function test_rebinding_a_resolved_singleton_wins(): void
    {
        $container = new Container();
        $container->singleton(SomeInterface::class, fn () => new ConcreteImplementation('first'));
        $this->assertSame('first', $container->get(SomeInterface::class)->value);

        $container->singleton(SomeInterface::class, fn () => new ConcreteImplementation('second'));

        $this->assertSame('second', $container->get(SomeInterface::class)->value);
        $this->assertSame($container->get(SomeInterface::class), $container->get(SomeInterface::class));
    }

    public function test_bind_drops_a_resolved_singleton(): void
    {
        $container = new Container();
        $container->singleton(SomeInterface::class, fn () => new ConcreteImplementation('first'));
        $container->get(SomeInterface::class);

        $container->bind(SomeInterface::class, fn () => new ConcreteImplementation('second'));

        $this->assertSame('second', $container->get(SomeInterface::class)->value);
    }

    public function test_singleton_attribute_shares_an_autowired_class(): void
    {
        $container = new Container();

        $this->assertSame($container->get(SharedService::class), $container->get(SharedService::class));
        $this->assertFalse($container->bound(SharedService::class));
        $this->assertNotSame($container->get(SimpleService::class), $container->get(SimpleService::class));
    }

    public function test_singleton_attribute_shares_a_class_resolved_as_a_dependency(): void
    {
        $container = new Container();
        $first = $container->get(SharedService::class);
        $container->bind('consumer', fn (Container $c) => $c->get(SharedService::class));

        $this->assertSame($first, $container->get('consumer'));
    }
}
