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

    /** @var array<string, true> the steps that run after every other one (last: true) */
    private array $last = [];

    /** @var array<string, \Closure> read-only checks `preflow migrate --pretend` runs */
    private array $checks = [];

    /** @var array<string, true> the checks that run on any storage */
    private array $anyStorageChecks = [];

    /** @var array<string, \Closure> read-only warnings `preflow migrate` and `db:check` print */
    private array $warnings = [];

    /** @var array<string, true> the warnings that work on any storage */
    private array $anyStorageWarnings = [];

    /** The storage the migrations run on in this run (ranOn()), null before or without one. */
    private ?string $storage = null;

    /**
     * Names the storage `preflow migrate` runs the migrations on, before it runs the steps: a step
     * that works on what the migrations made (their tables, MigrationColumns) works there only.
     *
     * @since 0.78
     */
    public function ranOn(?string $storage): void
    {
        $this->storage = $storage;
    }

    /** @since 0.78 */
    public function storage(): ?string
    {
        return $this->storage;
    }

    /**
     * A read-only check `preflow migrate --pretend` runs instead of the steps: it reports what a
     * step would refuse (lines starting with "refused:") and changes nothing. sqlOnly as for
     * afterMigrate().
     *
     * @param \Closure(): list<string> $check
     * @since 0.78
     */
    public function check(string $name, \Closure $check, bool $sqlOnly = true): void
    {
        if (isset($this->checks[$name])) {
            throw new \RuntimeException("Migration check [{$name}] is already registered.");
        }
        $this->checks[$name] = $check;
        if (!$sqlOnly) {
            $this->anyStorageChecks[$name] = true;
        }
    }

    /**
     * @return array<string, \Closure> the checks, in the order registered (as steps())
     * @since 0.78
     */
    public function checks(?bool $sql = null): array
    {
        return $sql === false ? array_intersect_key($this->checks, $this->anyStorageChecks) : $this->checks;
    }

    /**
     * A warning `preflow migrate` (also with --pretend) prints after its steps, as
     * "[name] warning: line", and `db:check` after its own lines: lines about the site's data that
     * need a person (a layout whose class is gone, pages still on it). It only reads, and never
     * changes an exit code. $sqlOnly true: only a site with SQL storage prints it (as check()); the
     * default, false, is a warning that works through DataManager on any storage.
     *
     * @param \Closure(): array<array-key, string> $lines (any array of lines; linesOf() lists them)
     * @since 0.92.0
     */
    public function warning(string $name, \Closure $lines, bool $sqlOnly = false): void
    {
        if (isset($this->warnings[$name])) {
            throw new \RuntimeException("Migration warning [{$name}] is already registered.");
        }
        $this->warnings[$name] = $lines;
        if (!$sqlOnly) {
            $this->anyStorageWarnings[$name] = true;
        }
    }

    /**
     * @return array<string, \Closure> the warnings, in the order registered; with $sql false only those that work on any storage
     * @since 0.92.0
     */
    public function warnings(?bool $sql = null): array
    {
        return $sql === false ? array_intersect_key($this->warnings, $this->anyStorageWarnings) : $this->warnings;
    }

    /**
     * What the warnings say: name => lines. A warning that throws is one line saying so, so a
     * warning can never stop `migrate` or `db:check`.
     *
     * @param array<string, \Closure(): array<array-key, string>> $warnings (warnings())
     * @return array<string, list<string>>
     * @since 0.92.0
     */
    public static function linesOf(array $warnings): array
    {
        $lines = [];
        foreach ($warnings as $name => $warning) {
            try {
                $lines[$name] = array_values($warning());
            } catch (\Throwable $e) {
                $lines[$name] = ['could not be worked out: ' . $e->getMessage()];
            }
        }

        return $lines;
    }

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
     * @param bool $last true: the step runs after every other one (a check of what they all did),
     *        whenever it is registered; last steps keep their order among themselves (@since 0.78)
     */
    public function afterMigrate(string $name, \Closure $step, bool $sqlOnly = true, bool $last = false): void
    {
        if (isset($this->steps[$name])) {
            throw new \RuntimeException("Migration step [{$name}] is already registered.");
        }

        $this->steps[$name] = $step;
        if (!$sqlOnly) {
            $this->anyStorage[$name] = true;
        }
        if ($last) {
            $this->last[$name] = true;
        }
    }

    /**
     * The steps, in the order registered (the last ones after the rest): every step for a site with SQL storage ($sql true or
     * null), only those registered with sqlOnly: false for one without ($sql false).
     *
     * @return array<string, \Closure>
     */
    public function steps(?bool $sql = null): array
    {
        $steps = [...array_diff_key($this->steps, $this->last), ...array_intersect_key($this->steps, $this->last)];

        return $sql === false ? array_intersect_key($steps, $this->anyStorage) : $steps;
    }

    /** Whether the step runs only where the site has SQL storage (false for an unknown name). @since 0.77.1 */
    public function isSqlOnly(string $name): bool
    {
        return isset($this->steps[$name]) && !isset($this->anyStorage[$name]);
    }
}
