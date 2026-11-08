<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

use Preflow\Data\Observe\DataObserver;

/**
 * Attached to the DataManager while the page cache is switched off and its enabled marker still
 * exists: the first write removes the marker (switching the cache on again then clears what it
 * held) and detaches this observer, so later reads and raw queries cost nothing.
 */
final class DisabledWritesObserver implements DataObserver
{
    private bool $done = false;

    /** @param \Closure(): void $detach takes this observer off the DataManager */
    public function __construct(
        private readonly EnabledMarker $marker,
        private readonly \Closure $detach,
    ) {}

    public function read(string $storage, string $type, ?string $id): void {}

    public function rawRead(string $storage, string $sql): void {}

    public function wrote(string $storage, string $type, ?string $id): void
    {
        $this->changed();
    }

    public function rawWrote(string $storage, string $sql): void
    {
        $this->changed();
    }

    private function changed(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->marker->remove();
        ($this->detach)();
    }
}
