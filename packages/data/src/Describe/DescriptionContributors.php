<?php

declare(strict_types=1);

namespace Preflow\Data\Describe;

/**
 * The packages' parts of the description, in the order added. Each is built on first use, once.
 *
 * @since 0.93.0
 */
final class DescriptionContributors
{
    /** @var list<\Closure(): DescriptionContributor> */
    private array $factories = [];

    /** @var array<int, DescriptionContributor> built ones, by their place in $factories */
    private array $built = [];

    /** @param \Closure(): DescriptionContributor $contributor */
    public function add(\Closure $contributor): void
    {
        $this->factories[] = $contributor;
    }

    /** @return list<DescriptionContributor> */
    public function all(): array
    {
        $all = [];
        foreach ($this->factories as $at => $factory) {
            $all[] = $this->built[$at] ??= $factory();
        }

        return $all;
    }
}
