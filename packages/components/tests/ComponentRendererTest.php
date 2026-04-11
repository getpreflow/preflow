<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;
use Preflow\View\TemplateEngineInterface;

class RenderableComponent extends Component
{
    public string $title = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Hello';
    }
}

class BrokenComponent extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('State resolution failed');
    }
}

class BrokenWithFallback extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('Broke');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>Component unavailable</p>';
    }
}

class SectionComponent extends Component
{
    protected string $tag = 'section';
}

class FakeTemplateEngine implements TemplateEngineInterface
{
    public ?string $lastTemplate = null;
    public ?array $lastContext = null;
    public string $output = '<p>rendered</p>';

    public function render(string $template, array $context = []): string
    {
        $this->lastTemplate = $template;
        $this->lastContext = $context;
        return $this->output;
    }

    public function exists(string $template): bool
    {
        return true;
    }
}

final class ComponentRendererTest extends TestCase
{
    private FakeTemplateEngine $engine;
    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        $this->engine = new FakeTemplateEngine();
        $this->renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(debug: DebugLevel::On),
        );
    }

    public function test_renders_component_with_wrapper(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Test']);

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('<div id="', $html);
        $this->assertStringContainsString('</div>', $html);
        $this->assertStringContainsString('<p>rendered</p>', $html);
    }

    public function test_wrapper_has_component_id(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Test']);

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('id="' . $component->getComponentId() . '"', $html);
    }

    public function test_resolves_state_before_render(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Resolved']);

        $this->renderer->render($component);

        $this->assertSame('Resolved', $this->engine->lastContext['title']);
    }

    public function test_passes_template_context_to_engine(): void
    {
        $component = new RenderableComponent();
        $component->setProps(['title' => 'Context']);

        $this->renderer->render($component);

        $this->assertArrayHasKey('title', $this->engine->lastContext);
        $this->assertArrayHasKey('componentId', $this->engine->lastContext);
    }

    public function test_uses_co_located_template_path(): void
    {
        $component = new RenderableComponent();

        $this->renderer->render($component);

        $this->assertStringEndsWith('RenderableComponent.twig', $this->engine->lastTemplate);
    }

    public function test_error_boundary_catches_resolve_state_error(): void
    {
        $component = new BrokenComponent();

        $html = $this->renderer->render($component);

        // Error boundary output (dev mode)
        $this->assertStringContainsString('State resolution failed', $html);
        $this->assertStringContainsString('BrokenComponent', $html);
    }

    public function test_error_boundary_shows_phase(): void
    {
        $component = new BrokenComponent();

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('resolveState', $html);
    }

    public function test_prod_error_boundary_uses_fallback(): void
    {
        $renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(debug: DebugLevel::Off),
        );

        $component = new BrokenWithFallback();

        $html = $renderer->render($component);

        $this->assertStringContainsString('Component unavailable', $html);
        $this->assertStringNotContainsString('Broke', $html);
    }

    public function test_custom_tag_in_wrapper(): void
    {
        $component = new SectionComponent();

        $html = $this->renderer->render($component);

        $this->assertStringContainsString('<section id="', $html);
        $this->assertStringContainsString('</section>', $html);
    }

    public function test_render_fragment_returns_inner_html_only(): void
    {
        $component = new RenderableComponent();

        $html = $this->renderer->renderFragment($component);

        $this->assertSame('<p>rendered</p>', $html);
        $this->assertStringNotContainsString('<div id=', $html);
    }
}
