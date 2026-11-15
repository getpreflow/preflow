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

    /** @var array<string, true> the steps that run on any storage (sqlOnly: false) */
    private array $anyStorage = [];

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
     * A step `preflow migrate` runs after the migrations, in the order registered.
     *
     * A step is SQL-only by default: it runs where the site has SQL storage (a table or column
     * sync). A step that works through DataManager on any storage (marking records, seeding
     * rows) passes sqlOnly: false, and migrate runs it on a site whose storage is JSON only too.
     *
     * @param \Closure(): list<string> $step Returns the changes made, one line each
     * @param bool $sqlOnly false: the step runs on any storage (@since 0.77.1)
     */
    public function afterMigrate(string $name, \Closure $step, bool $sqlOnly = true): void
    {
        if (isset($this->steps[$name])) {
            throw new \RuntimeException("Migration step [{$name}] is already registered.");
        }

        $this->steps[$name] = $step;
        if (!$sqlOnly) {
            $this->anyStorage[$name] = true;
        }
    }

    /**
     * The steps, in the order registered: every step for a site with SQL storage ($sql true or
     * null), only those registered with sqlOnly: false for one without ($sql false).
     *
     * @return array<string, \Closure>
     */
    public function steps(?bool $sql = null): array
    {
        if ($sql !== false) {
            return $this->steps;
        }

        return array_intersect_key($this->steps, $this->anyStorage);
    }

    /** Whether the step runs only where the site has SQL storage (false for an unknown name). @since 0.77.1 */
    public function isSqlOnly(string $name): bool
    {
        return isset($this->steps[$name]) && !isset($this->anyStorage[$name]);
    }
}
