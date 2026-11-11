<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;

/** A service only a service provider binds (an interface: the container can't autowire it). */
interface Greeting
{
    public function text(): string;
}

final class ProviderGreeting implements Greeting
{
    public function text(): string
    {
        return 'hello from a provider';
    }
}

final class GreetingProvider extends ServiceProvider
{
    public function register(Container $container): void
    {
        $container->instance(Greeting::class, new ProviderGreeting());
    }
}

/** An engine factory, a hypermedia driver and a storage driver that need the provider's service. */
final class GreetingEngineFactory implements \Preflow\View\TemplateEngineFactory
{
    public function __construct(private readonly Greeting $greeting) {}

    public static function templateExtension(): string
    {
        return 'phtml';
    }

    public function create(\Preflow\View\TemplateEngineContext $context): \Preflow\View\TemplateEngineInterface
    {
        $engine = new PhtmlEngine($context->templateDirs);
        $engine->addGlobal('greeting', $this->greeting->text());

        return $engine;
    }
}

final class GreetingSignalDriver extends SignalDriver
{
    public function __construct(\Preflow\View\AssetCollector $assets, public readonly Greeting $greeting)
    {
        parent::__construct($assets);
    }
}

final class GreetingMemoryDriver extends MemoryDriver
{
    public function __construct(\Preflow\Core\Http\NonPublicPaths $paths, public readonly Greeting $greeting, string $label = 'unset')
    {
        parent::__construct($paths, $label);
    }
}
