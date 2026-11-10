<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Rendering;

use Preflow\Components\Component;
use Preflow\Core\Exceptions\ForbiddenHttpException;

/** Fixture components for PageRenderingTest; each template sits next to this file. */
class RenderingOuter extends Component {}

class RenderingForbidden extends Component
{
    public function resolveState(): void
    {
        throw new ForbiddenHttpException('Only staff of App\Secret\StaffArea');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>Fallback shown</p>';
    }
}

class RenderingBroken extends Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('Broken internals');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<p>Fallback shown</p>';
    }
}
