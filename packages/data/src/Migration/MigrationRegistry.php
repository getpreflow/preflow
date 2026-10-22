<?php

declare(strict_types=1);

namespace Preflow\Data\Migration;

/**
 * Collects migration directories and post-migrate steps from the app and its packages.
 */
final class MigrationRegistry
{
    /** @var list<string> */
    private array $paths = [];

    /** @var array<string, \Closure> */
    private array $steps = [];

    /** Missing directories are ignored. */
    public function addPath(string $dir): void
    {
        if (is_dir($dir) && !in_array($dir, $this->paths, true)) {
            $this->paths[] = $dir;
        }
    }

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    /**
     * @param \Closure(): list<string> $step Returns the changes made, one line each
     */
    public function afterMigrate(string $name, \Closure $step): void
    {
        if (isset($this->steps[$name])) {
            throw new \RuntimeException("Migration step [{$name}] is already registered.");
        }

        $this->steps[$name] = $step;
    }

    /**
     * @return array<string, \Closure>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
