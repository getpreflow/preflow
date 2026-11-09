<?php

declare(strict_types=1);

namespace App\Components\ErrorDemo;

use Preflow\Components\Component;
use Preflow\Components\PreviewsFailure;

/**
 * Shows what an error boundary does: the page renders what the boundary would show if this
 * component failed (its fallback()), as a preview. Nothing throws, so the page cache still stores
 * the page. Delete this component and app/pages/about-error-boundaries.twig (and its footer link
 * in _layout.twig) when you don't need the demo.
 */
final class ErrorDemo extends Component implements PreviewsFailure
{
    public function previewFailure(): \Throwable
    {
        return new \RuntimeException('This component crashed on purpose, but the rest of the page survived.');
    }

    public function fallback(\Throwable $e): ?string
    {
        return <<<'HTML'
        <div class="error-demo-fallback">
            <span class="error-demo-icon">&#9888;</span>
            <div>
                <strong>Error Boundary: this is a preview</strong>
                <p>Every component sits inside an error boundary. If its code throws, only that
                component is replaced by its <code>fallback()</code>, like this box, and the rest of
                the page keeps rendering. In development, set <code>APP_DEBUG=2</code> to see the
                full error panel instead.</p>
            </div>
        </div>
        HTML;
    }
}
