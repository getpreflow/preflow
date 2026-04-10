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
