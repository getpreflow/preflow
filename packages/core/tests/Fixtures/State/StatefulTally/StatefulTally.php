<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\State\StatefulTally;

use Preflow\Components\Component;
use Preflow\Components\State;

/** A component with state, mounted from its "start" prop. */
class StatefulTally extends Component
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

    public function actionBump(): void
    {
        $this->tally++;
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return __DIR__ . '/StatefulTally.' . $extension;
    }
}
