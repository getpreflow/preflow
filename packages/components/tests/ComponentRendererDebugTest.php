<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class ComponentRendererDebugTest extends TestCase
{
    public function test_render_logs_component(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn('<div>rendered</div>');
        $engine->method('getTemplateExtension')->willReturn('twig');

        $collector = new DebugCollector();
        $renderer = new ComponentRenderer(
            $engine,
            new ErrorBoundary(debug: DebugLevel::Off),
            $collector,
        );

        $component = new class extends \Preflow\Components\Component {
            public string $uuid = '';
            public function getTemplatePath(string $extension = 'twig'): string
            {
                return '/tmp/fake.' . $extension;
            }
        };

        $renderer->render($component);

        $components = $collector->getComponents();
        $this->assertCount(1, $components);
        $this->assertGreaterThanOrEqual(0.0, $components[0]['duration_ms']);
    }

    public function test_no_collector_no_logging(): void
    {
        $engine = $this->createStub(TemplateEngineInterface::class);
        $engine->method('render')->willReturn('<div>rendered</div>');
        $engine->method('getTemplateExtension')->willReturn('twig');

        $collector = new DebugCollector();
        $renderer = new ComponentRenderer(
            $engine,
            new ErrorBoundary(debug: DebugLevel::Off),
        );

        $component = new class extends \Preflow\Components\Component {
            public string $uuid = '';
            public function getTemplatePath(string $extension = 'twig'): string
            {
                return '/tmp/fake.' . $extension;
            }
        };

        $renderer->render($component);
        $this->assertCount(0, $collector->getComponents());
    }
}
