<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * A #[Live] component that loads what all its instances on a page need at once. Before a
 * served page's live parts are rendered, prefetchLive() is called once per class with the
 * props of every instance of it in the page, on an instance made like the others (constructor
 * injection, empty props). Keep what it loads where the instances find it: an injected service
 * that memoises (a likes repository), or a static.
 *
 *     public function prefetchLive(array $propsList): void
 *     {
 *         $this->likes->prefetch(array_column($propsList, 'number'));
 *     }
 */
interface PrefetchesLive
{
    /** @param list<array<string, mixed>> $propsList the props of each instance */
    public function prefetchLive(array $propsList): void;
}
