<?php

declare(strict_types=1);

namespace Preflow\Components\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\Twig\ComponentExtension;
use Preflow\View\TemplateEngineInterface;

class TwigTestComponent extends Component
{
    public string $message = '';

    public function resolveState(): void
    {
        $this->message = $this->props['message'] ?? 'default';
    }
}

class TwigBrokenComponent extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('Component broke');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>Fallback rendered</p>';
    }
}

class StubTemplateEngine implements TemplateEngineInterface
{
    public function render(string $template, array $context = []): string
    {
        return '<p>' . ($context['message'] ?? 'no message') . '</p>';
    }

    public function exists(string $template): bool
    {
        return true;
    }
}

final class ComponentExtensionTest extends TestCase
{
    private Environment $twig;
    private ComponentRenderer $renderer;

    /** @var array<string, class-string<Component>> */
    private array $componentMap;

    protected function setUp(): void
    {
        $engine = new StubTemplateEngine();
        $this->renderer = new ComponentRenderer(
            templateEngine: $engine,
            errorBoundary: new ErrorBoundary(debug: false),
        );

        $this->componentMap = [
            'TestComponent' => TwigTestComponent::class,
            'BrokenComponent' => TwigBrokenComponent::class,
        ];

        $extension = new ComponentExtension($this->renderer, $this->componentMap);

        $this->twig = new Environment(new ArrayLoader([]), [
            'autoescape' => false,
        ]);
        $this->twig->addExtension($extension);
    }

    private function render(string $template): string
    {
        return $this->twig->createTemplate($template)->render([]);
    }

    public function test_component_function_renders_component(): void
    {
        $result = $this->render("{{ component('TestComponent', { message: 'Hello' }) }}");

        $this->assertStringContainsString('Hello', $result);
    }

    public function test_component_function_wraps_in_div(): void
    {
        $result = $this->render("{{ component('TestComponent') }}");

        $this->assertStringContainsString('<div id="', $result);
        $this->assertStringContainsString('</div>', $result);
    }

    public function test_component_function_with_error_uses_fallback(): void
    {
        $result = $this->render("{{ component('BrokenComponent') }}");

        $this->assertStringContainsString('Fallback rendered', $result);
    }

    public function test_unknown_component_throws(): void
    {
        try {
            $this->render("{{ component('NonExistent') }}");
            $this->fail('Expected exception was not thrown');
        } catch (\Twig\Error\RuntimeError $e) {
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\InvalidArgumentException::class, $previous);
            $this->assertStringContainsString('Unknown component', $previous->getMessage());
        }
    }

    public function test_component_renders_alongside_html(): void
    {
        $result = $this->render("<h1>Title</h1>{{ component('TestComponent', { message: 'World' }) }}<footer>end</footer>");

        $this->assertStringContainsString('<h1>Title</h1>', $result);
        $this->assertStringContainsString('World', $result);
        $this->assertStringContainsString('<footer>end</footer>', $result);
    }
}
