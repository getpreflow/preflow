<?php

declare(strict_types=1);

namespace App\Components\ErrorDemo;

use Preflow\Components\Component;

final class ErrorDemo extends Component
{
    public function resolveState(): void
    {
        // This component deliberately throws to showcase error boundaries
        throw new \RuntimeException(
            'This component crashed on purpose! But the rest of the page survived.'
        );
    }

    public function fallback(\Throwable $e): ?string
    {
        return <<<'HTML'
        <div class="error-demo-fallback">
            <span class="error-demo-icon">&#9888;</span>
            <div>
                <strong>Error Boundary — Custom Fallback</strong>
                <p>This component crashed, but it defined a <code>fallback()</code> method.
                The rest of the page kept rendering. Custom fallbacks are shown in normal debug mode
                (level 0 and 1). Use level 2 (verbose) to override them with the dev error panel.</p>
            </div>
        </div>
        HTML;
    }
}
