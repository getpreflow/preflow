<?php

declare(strict_types=1);

namespace App\Components\ErrorDemo;

use Preflow\Components\Component;
use Preflow\Components\PreviewsFailure;

/**
 * Showcases error boundaries: the page shows exactly what the boundary renders when this
 * component fails (its fallback()), as a preview. Nothing throws, so the page cache still
 * stores the home page; a component that really fails keeps its page from being stored.
 */
final class ErrorDemo extends Component implements PreviewsFailure
{
    public function previewFailure(): \Throwable
    {
        return new \RuntimeException('This component crashed on purpose! But the rest of the page survived.');
    }

    public function fallback(\Throwable $e): ?string
    {
        return <<<'HTML'
        <div class="error-demo-fallback">
            <span class="error-demo-icon">&#9888;</span>
            <div>
                <strong>Error Boundary — Custom Fallback</strong>
                <p>A preview of a failed component: when a component throws, its <code>fallback()</code>
                is shown instead and the rest of the page keeps rendering. Custom fallbacks are shown in
                normal debug mode (level 0 and 1). Use level 2 (verbose) to override them with the dev
                error panel.</p>
            </div>
        </div>
        HTML;
    }
}
