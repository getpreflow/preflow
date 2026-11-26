<?php

declare(strict_types=1);

namespace Preflow\Blocks\Layouts;

/**
 * The area layout sets by name. A package registers its set in its service provider; the set is
 * built on first use.
 *
 * @since 0.92.0
 */
final class AreaLayoutSets
{
    /** @var array<string, \Closure(): AreaLayoutSet> */
    private array $factories = [];

    /** @var array<string, AreaLayoutSet> */
    private array $built = [];

    /** @param \Closure(): AreaLayoutSet $set */
    public function add(string $name, \Closure $set): void
    {
        if (isset($this->factories[$name])) {
            throw new \RuntimeException(sprintf('The area layout set "%s" is registered twice; register it once, in one service provider.', $name));
        }
        $this->factories[$name] = $set;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * The registered names, in registration order (ContentDescription lists their sets).
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map('strval', array_keys($this->factories));
    }

    public function get(string $name): AreaLayoutSet
    {
        if (!isset($this->factories[$name])) {
            throw new LayoutConfigException(sprintf('No package registers the area layout set "%s". The package that brings the block registers it in its service provider (config/providers.php).', $name));
        }

        return $this->built[$name] ??= ($this->factories[$name])();
    }
}
