<?php

declare(strict_types=1);

namespace Preflow\Media\Derived;

use Preflow\Core\Container\Attributes\Singleton;
use Preflow\Media\MediaLibrary;

/**
 * The makers copies can be made again with, by name. One per container from its first
 * resolution, whichever provider asks first, so a maker registered before
 * MediaServiceProvider is kept. A later maker of the same name replaces the earlier one.
 */
#[Singleton]
final class DerivedMakers
{
    /** @var array<string, DerivedMaker> */
    private array $makers = [];

    /** @throws \InvalidArgumentException for the library's own name "copy" (a plain copy has no maker to call) */
    public function register(DerivedMaker $maker): void
    {
        $name = $maker->name();
        if ($name === MediaLibrary::MAKER_COPY) {
            throw new \InvalidArgumentException('The maker name "' . MediaLibrary::MAKER_COPY . '" is the media library\'s own (a plain copy); choose another.');
        }
        $this->makers[$name] = $maker;
    }

    public function get(string $name): ?DerivedMaker
    {
        return $this->makers[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->makers[$name]);
    }
}
