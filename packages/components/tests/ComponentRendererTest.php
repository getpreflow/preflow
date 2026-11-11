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

class ForbiddenComponent extends Component
{
    public function resolveState(): void
    {
        throw new \Preflow\Core\Exceptions\ForbiddenHttpException('Members only');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>fallback</p>';
    }
}

class SectionComponent extends Component
{
    protected string $tag = 'section';
}

class StyledComponent extends Component
{
    protected string $cssClass = 'my-widget';
}

class ScopedComponent extends Component
{
    protected string $cssClass = 'scoped-widget';
    protected bool $scopeCss = true;
}

class FakeTemplateEngine implements TemplateEngineInterface
{
    public ?string $lastTemplate = null;
    public ?array $lastContext = null;
    public string $output = '<p>rendered</p>';
    /** @var (\Closure(string): string)|null what rendering the template does instead of returning $output */
    public ?\Closure $onRender = null;

    public function render(string $template, array $context = []): string
    {
        $this->lastTemplate = $template;
        $this->lastContext = $context;
        return $this->onRender !== null ? ($this->onRender)($template) : $this->output;
    }

    public function exists(string $template): bool
    {
        return true;
    }

    public function addFunction(\Preflow\View\TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }

    public function addNamespace(string $namespace, string $path): void {}
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

    public function test_root_attributes_are_added_to_the_wrapper(): void
    {
        $component = new RenderableComponent();
        $component->setRootAttribute('data-folio-block', 'a"b');
        $component->setRootAttribute('data-folio-label', 'Hero');
        $component->setRootAttribute('onclick', 'x()'); // only data-* attributes
        $component->setRootAttribute('id', 'other');

        $html = $this->renderer->render($component);

        $this->assertMatchesRegularExpression('#^<div id="RenderableComponent-[^"]+" data-folio-block="a&quot;b" data-folio-label="Hero">#', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertSame(1, substr_count($html, 'id="'));
    }

    public function test_root_attributes_may_be_datastar_style(): void
    {
        $component = new RenderableComponent();
        $component->setRootAttribute('data-on:click__debounce.500ms', '@post("/x")');
        $component->setRootAttribute('data-signals:count', '0');
        $component->setRootAttribute('data-on:click x="y"', 'no'); // a space or quote would break the attribute
        $component->setRootAttribute('data-on:<b>', 'no');
        $component->setRootAttribute('data-', 'no');

        $html = $this->renderer->render($component);

        $this->assertMatchesRegularExpression(
            '#^<div id="RenderableComponent-[^"]+" data-on:click__debounce.500ms="@post\(&quot;/x&quot;\)" data-signals:count="0">#',
            $html,
        );
        $this->assertStringNotContainsString('no', substr($html, 0, (int) strpos($html, '>')));
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

    public function test_wrapper_includes_css_class(): void
    {
        $component = new StyledComponent();
        $html = $this->renderer->render($component);

        $this->assertStringContainsString('class="my-widget"', $html);
        $this->assertStringContainsString('<div id="', $html);
    }

    public function test_scoped_component_has_hashed_css_class(): void
    {
        $component = new ScopedComponent();
        $cssClass = $component->getCssClass();

        // Should be "scoped-widget-" + 6 char hex hash
        $this->assertMatchesRegularExpression('/^scoped-widget-[a-f0-9]{6}$/', $cssClass);
    }

    public function test_scoped_css_class_is_stable(): void
    {
        $a = new ScopedComponent();
        $b = new ScopedComponent();

        // Same class = same hash, regardless of props
        $this->assertSame($a->getCssClass(), $b->getCssClass());

        $b->setProps(['different' => 'props']);
        $this->assertSame($a->getCssClass(), $b->getCssClass());
    }

    public function test_css_scoping_wraps_collected_css(): void
    {
        $nonce = new \Preflow\View\NonceGenerator();
        $assets = new \Preflow\View\AssetCollector($nonce);

        $renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(debug: DebugLevel::On),
            assetCollector: $assets,
        );

        // Simulate: template calls addCss during render
        $this->engine->output = '<p>scoped content</p>';

        $component = new ScopedComponent();
        $cssClass = $component->getCssClass();

        // Manually test the scope mechanism
        $assets->setCssScope($cssClass);
        $assets->addCss('.title { color: red; }');
        $assets->setCssScope(null);

        $css = $assets->renderCss();
        $this->assertStringContainsString('.' . $cssClass, $css);
        $this->assertStringContainsString('.title { color: red; }', $css);
    }

    public function test_an_http_exception_is_rethrown_not_caught_by_the_boundary(): void
    {
        $errors = [];
        $renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(DebugLevel::Off, static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e;
            }),
        );

        // From resolveState(), even with a fallback: a 403/404 is the page's answer.
        foreach (['render', 'renderFragment'] as $method) {
            try {
                $renderer->{$method}(new ForbiddenComponent());
                $this->fail($method . ' should rethrow');
            } catch (\Preflow\Core\Exceptions\ForbiddenHttpException $e) {
                $this->assertSame('Members only', $e->getMessage());
            }
        }

        // From the template (after an action too).
        $this->engine->onRender = static fn (): string => throw new \Preflow\Core\Exceptions\NotFoundHttpException('No such game');
        foreach (['render', 'renderFragment', 'renderResolved', 'renderResolvedFragment'] as $method) {
            try {
                $renderer->{$method}(new RenderableComponent());
                $this->fail($method . ' should rethrow');
            } catch (\Preflow\Core\Exceptions\NotFoundHttpException $e) {
                $this->assertSame(404, $e->statusCode);
            }
        }
        $this->assertSame([], $errors, 'not reported as a component error');

        // Any other failure keeps the boundary.
        $this->engine->onRender = static fn (): string => throw new \RuntimeException('broken');
        $this->assertStringContainsString('Component unavailable', (new ComponentRenderer($this->engine, new ErrorBoundary(DebugLevel::Off)))->render(new BrokenWithFallback()));
        $this->assertIsString($renderer->render(new RenderableComponent()));
        $this->assertCount(1, $errors);
    }

    public function test_an_unscoped_child_does_not_take_its_parents_css_scope(): void
    {
        $assets = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
        $renderer = new ComponentRenderer(
            templateEngine: $this->engine,
            errorBoundary: new ErrorBoundary(debug: DebugLevel::On),
            assetCollector: $assets,
        );
        $parent = new ScopedComponent();
        $seen = [];
        $this->engine->onRender = function () use ($assets, $renderer, &$seen): string {
            $seen[] = $assets->getCssScope();
            if (count($seen) === 1) {
                // The parent's template renders an unscoped child, then a failing one.
                $renderer->render(new RenderableComponent());
                $seen[] = $assets->getCssScope();
            }

            return '<p>x</p>';
        };

        $renderer->render($parent);

        $this->assertSame([$parent->getCssClass(), null, $parent->getCssClass()], $seen);
        $this->assertNull($assets->getCssScope());

        // A template that throws puts the scope back too.
        $this->engine->onRender = static fn (): string => throw new \RuntimeException('broken');
        $renderer->render(new ScopedComponent());
        $this->assertNull($assets->getCssScope());
    }

    public function test_component_without_css_class_has_no_class_attr(): void
    {
        $component = new RenderableComponent();
        $html = $this->renderer->render($component);

        $this->assertStringNotContainsString('class=', $html);
    }

    public function test_render_fragment_returns_inner_html_only(): void
    {
        $component = new RenderableComponent();

        $html = $this->renderer->renderFragment($component);

        $this->assertSame('<p>rendered</p>', $html);
        $this->assertStringNotContainsString('<div id=', $html);
    }
}
