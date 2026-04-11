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
            . '<strong>Error Boundary Caught This</strong>'
            . '<p>The component threw an exception, but the page kept rendering. '
            . 'In production, users see this fallback. In dev mode, you\'d see the full stack trace.</p>'
            . '</div>'
            . '</div>';
    }
}
