<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * The change-date sources packages register (ChangeDateSource), for `preflow migrate`'s backfill.
 * The core binds one; a package's provider adds its own under a name of its own.
 *
 * @since 0.84.0
 */
final class ChangeDateSources
{
    /** @var array<string, ChangeDateSource> */
    private array $sources = [];

    /** @since 0.84.0 */
    public function add(string $name, ChangeDateSource $source): void
    {
        if (isset($this->sources[$name])) {
            throw new \RuntimeException("Change date source [{$name}] is already registered.");
        }
        $this->sources[$name] = $source;
    }

    /**
     * @return list<ChangeDateSource> in the order added
     * @since 0.84.0
     */
    public function all(): array
    {
        return array_values($this->sources);
    }
}
