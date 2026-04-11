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
        return '<div class="error-demo-fallback">'
            . '<span class="error-demo-icon">&#9888;</span>'
            . '<div>'
            . '<strong>Error Boundary — Custom Fallback</strong>'
            . '<p>This component crashed, but it defined a <code>fallback()</code> method. '
            . 'The rest of the page kept rendering. Custom fallbacks are always shown, regardless of debug mode.</p>'
            . '</div>'
            . '</div>';
    }
}
