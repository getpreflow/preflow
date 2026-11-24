<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests\Fixtures\State;

use Preflow\Components\Component;
use Preflow\Components\State;

/** A component with state: a tally mounted from its "start" prop; "bump" adds the posted "by". */
final class Tally extends Component
{
    #[State] public int $tally = 0;

    public function mount(): void
    {
        $this->tally = (int) ($this->props['start'] ?? 0);
    }

    public function actions(): array
    {
        return ['bump'];
    }

    /** @param array<string, mixed> $params */
    public function actionBump(array $params = []): void
    {
        $this->tally += (int) ($params['by'] ?? 1);
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/Tally.' . $extension;
    }
}
