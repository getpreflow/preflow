<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ErrorBoundary;
use Preflow\Core\DebugLevel;

class FallbackComponent extends Component
{
    public function fallback(\Throwable $e): string
    {
        return '<div class="custom-fallback">Oops</div>';
    }
}

class NoFallbackComponent extends Component
{
}

final class ErrorBoundaryTest extends TestCase
{
    public function test_dev_mode_shows_exception_class(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('Something broke');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('RuntimeException', $html);
    }

    public function test_dev_mode_shows_message(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('Detailed error info');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('Detailed error info', $html);
    }

    public function test_dev_mode_shows_component_class(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('NoFallbackComponent', $html);
    }

    public function test_dev_mode_shows_props(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new NoFallbackComponent();
        $component->setProps(['id' => '42', 'slug' => 'test']);
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('42', $html);
        $this->assertStringContainsString('test', $html);
    }

    public function test_dev_mode_shows_stack_trace(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('ErrorBoundaryTest.php', $html);
    }

    public function test_dev_mode_shows_lifecycle_phase(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component, 'resolveState');

        $this->assertStringContainsString('resolveState', $html);
    }

    public function test_on_mode_respects_custom_fallback(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::On);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('secret details');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('custom-fallback', $html);
        $this->assertStringNotContainsString('RuntimeException', $html);
    }

    public function test_prod_mode_uses_component_fallback(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Off);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('secret details');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('custom-fallback', $html);
        $this->assertStringContainsString('Oops', $html);
        $this->assertStringNotContainsString('secret details', $html);
    }

    public function test_prod_mode_uses_generic_fallback_when_no_custom(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Off);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('secret details');

        $html = $boundary->render($exception, $component);

        $this->assertStringNotContainsString('secret details', $html);
        $this->assertNotEmpty($html); // should render something
    }

    public function test_prod_mode_hides_stack_trace(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Off);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringNotContainsString('ErrorBoundaryTest.php', $html);
    }

    public function test_verbose_overrides_custom_fallback_with_dev_panel(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('Hidden by fallback normally');

        $html = $boundary->render($exception, $component);

        // Should show dev panel, not the custom fallback
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Hidden by fallback normally', $html);
        $this->assertStringNotContainsString('custom-fallback', $html);
    }

    public function test_verbose_shows_fallback_suppressed_note(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('custom fallback', $html);
        $this->assertStringContainsString('suppressed', $html);
    }

    public function test_verbose_shows_dev_panel_for_no_fallback_component(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('Verbose error');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Verbose error', $html);
    }

    public function test_verbose_shows_full_dev_panel_content(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new NoFallbackComponent();
        $component->setProps(['id' => '99']);
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component, 'resolveState');

        $this->assertStringContainsString('NoFallbackComponent', $html);
        $this->assertStringContainsString('resolveState', $html);
        $this->assertStringContainsString('99', $html);
        $this->assertStringContainsString('Stack Trace', $html);
    }
}
