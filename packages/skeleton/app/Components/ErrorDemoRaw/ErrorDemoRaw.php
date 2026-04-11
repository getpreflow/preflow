<?php

declare(strict_types=1);

namespace App\Components\ErrorDemoRaw;

use Preflow\Components\Component;

final class ErrorDemoRaw extends Component
{
    public function resolveState(): void
    {
        // No fallback defined — in debug mode, the dev error panel appears inline.
        // In production, a hidden div is rendered instead.
        throw new \RuntimeException(
            'This component has no fallback() — so the framework shows its own error panel.'
        );
    }
}
