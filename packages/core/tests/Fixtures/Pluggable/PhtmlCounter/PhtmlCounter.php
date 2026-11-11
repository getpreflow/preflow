<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable\PhtmlCounter;

use Preflow\Components\Component;
use Preflow\Htmx\HypermediaDriver;

/** A component with a .phtml template and an action that queues response effects. */
final class PhtmlCounter extends Component
{
    public int $count = 0;

    public function __construct(private readonly HypermediaDriver $driver) {}

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
        $this->driver->triggerEvent('counted');
        $this->driver->pushUrl('/count/' . $this->count);
    }
}
