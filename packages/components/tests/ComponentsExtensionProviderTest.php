<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\ComponentsExtensionProvider;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;
use Preflow\View\TemplateFunctionDefinition;
use Preflow\View\TemplateEngineInterface;

final class ComponentsExtensionProviderTest extends TestCase
{
    public function test_provides_component_function(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn('<div>rendered</div>');
        $engine->method('getTemplateExtension')->willReturn('twig');

        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::Off));
        $provider = new ComponentsExtensionProvider($renderer, [], null);
        $functions = $provider->getTemplateFunctions();

        $this->assertCount(1, $functions);
        $this->assertSame('component', $functions[0]->name);
        $this->assertTrue($functions[0]->isSafe);
        $this->assertInstanceOf(TemplateFunctionDefinition::class, $functions[0]);
    }

    public function test_globals_are_empty(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::Off));
        $provider = new ComponentsExtensionProvider($renderer, [], null);

        $this->assertSame([], $provider->getTemplateGlobals());
    }
}
