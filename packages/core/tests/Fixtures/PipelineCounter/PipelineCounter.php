<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\PipelineCounter;

use Preflow\Components\Component;
use Preflow\Htmx\Guarded;
use Psr\Http\Message\ServerRequestInterface;

/** A component for full-app endpoint tests: one action, and it records what authorize() saw. */
final class PipelineCounter extends Component implements Guarded
{
    public static ?ServerRequestInterface $seen = null;

    public int $count = 0;

    public function resolveState(): void
    {
        $this->count = (int) ($this->props['count'] ?? 0);
    }

    public function actions(): array
    {
        return ['bump'];
    }

    public function actionBump(array $params = []): void
    {
        $this->count++;
    }

    public function authorize(string $action, ServerRequestInterface $request): void
    {
        self::$seen = $request;
    }
}
