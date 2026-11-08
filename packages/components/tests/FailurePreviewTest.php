<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

require_once __DIR__ . '/ComponentRendererTest.php'; // FakeTemplateEngine, BrokenComponent

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\PreviewsFailure;
use Preflow\Core\DebugLevel;

class PreviewedFailure extends Component implements PreviewsFailure
{
    public bool $resolved = false;

    public function resolveState(): void
    {
        $this->resolved = true;
    }

    public function previewFailure(): \Throwable
    {
        return new \RuntimeException('Shown as a preview');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<div class="fallback">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

class PreviewedFailureWithoutFallback extends Component implements PreviewsFailure
{
    public function previewFailure(): \Throwable
    {
        return new \RuntimeException('No fallback here');
    }
}

class PreviewThatThrows extends Component implements PreviewsFailure
{
    public function previewFailure(): \Throwable
    {
        throw new \LogicException('the preview itself failed');
    }
}

/** A component that shows what its error boundary renders for a failure, without failing. */
final class FailurePreviewTest extends TestCase
{
    /** @var list<string> */
    private array $errors = [];

    private function boundary(DebugLevel $debug = DebugLevel::Off): ErrorBoundary
    {
        return new ErrorBoundary($debug, function (\Throwable $e): void {
            $this->errors[] = $e->getMessage();
        });
    }

    public function test_preview_renders_what_render_renders_without_reporting_an_error(): void
    {
        foreach ([DebugLevel::Off, DebugLevel::On, DebugLevel::Verbose] as $debug) {
            foreach ([new PreviewedFailure(), new PreviewedFailureWithoutFallback()] as $component) {
                $e = new \RuntimeException('boom');
                $preview = $this->boundary($debug)->preview($e, $component, 'resolveState');
                $this->assertSame([], $this->errors, 'a preview is no error');
                $this->assertSame($this->boundary($debug)->render($e, $component, 'resolveState'), $preview);
                $this->errors = [];
            }
        }
    }

    public function test_a_component_previewing_its_failure_renders_the_boundary_output_and_no_error(): void
    {
        $renderer = new ComponentRenderer(new FakeTemplateEngine(), $this->boundary());
        $component = new PreviewedFailure();

        $html = $renderer->render($component);

        $this->assertSame('<div class="fallback">Shown as a preview</div>', $html, 'exactly the boundary output, no wrapper');
        $this->assertSame([], $this->errors);
        $this->assertFalse($component->resolved, 'it never resolves or renders itself');
        $this->assertSame('<div style="display:none;" data-component-error="true"></div>', $renderer->render(new PreviewedFailureWithoutFallback()));
        $this->assertSame([], $this->errors);

        // A component that really fails is still reported.
        $renderer->render(new BrokenComponent());
        $this->assertSame(['State resolution failed'], $this->errors);
    }

    public function test_a_preview_that_throws_is_a_real_failure(): void
    {
        $renderer = new ComponentRenderer(new FakeTemplateEngine(), $this->boundary());

        $this->assertSame('<div style="display:none;" data-component-error="true"></div>', $renderer->render(new PreviewThatThrows()));
        $this->assertSame(['the preview itself failed'], $this->errors, 'reported: the page is not stored');
    }
}
