<?php

declare(strict_types=1);

namespace App\Components\ErrorDemoRaw;

use Preflow\Components\Component;
use Preflow\Components\PreviewsFailure;

/**
 * A preview of a failed component without fallback(): in debug mode the dev error panel appears
 * inline; in production a hidden div is rendered instead, as for a real failure.
 */
final class ErrorDemoRaw extends Component implements PreviewsFailure
{
    public function previewFailure(): \Throwable
    {
        return new \RuntimeException('This component has no fallback() — so the framework shows its own error panel.');
    }
}
