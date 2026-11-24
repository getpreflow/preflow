<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\State;

use Preflow\Components\StateIn;
use Preflow\Components\StateStore;
use Preflow\Core\Tests\Fixtures\State\StatefulTally\StatefulTally;

/** A store a test binds in the container: keeps the state in memory under a counter. */
final class MemoryStateStore implements StateStore
{
    /** @var array<string, array<string, mixed>> */
    public array $kept = [];

    public function put(string $componentClass, string $componentId, array $state): string
    {
        $ref = 'm' . count($this->kept);
        $this->kept[$ref] = $state;

        return $ref;
    }

    public function get(string $componentClass, string $componentId, string $carried): array
    {
        return $this->kept[$carried] ?? throw new \Preflow\Components\StateRefused('unknown');
    }
}

#[StateIn(MemoryStateStore::class)]
final class MemoryTally extends StatefulTally
{
}
