<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * Runs a set-up step (migrate, seed) once per state and restores what it left behind for every
 * later test that starts from the same state. "The same state" is every environment variable, the
 * content of every file under the registered roots and every registered database's tables: anything
 * a test changed before the step makes it a different state, and the step runs for real.
 *
 * Databases are written lazily: a restored step's tables (and reset()'s emptying) are written by
 * flush(), which the site calls before anything touches a database, so a restored migrate followed
 * by a restored seed writes the tables once.
 *
 * Snapshots live for one test run (PREFLOW_TEST_RUN, set by bootstrap() and inherited by child
 * processes); PREFLOW_TEST_SNAPSHOTS=0 turns them off.
 */
final class SetupSnapshots
{
    private const string RUN = 'PREFLOW_TEST_RUN';

    private const string SWITCH = 'PREFLOW_TEST_SNAPSHOTS';

    /** Snapshots this process used last, by file (the rest are only in their files). */
    private const int IN_MEMORY = 4;

    /** @var array<string, string> serialized: every hit unserializes its own copy of the result */
    private static array $memory = [];

    private readonly string $run;

    private readonly string $directory;

    /** @var array<string, string> placeholder => directory */
    private array $roots = [];

    /** @var list<string> paths like "{siteTmp}/error.log" */
    private array $ignored = [];

    private ?\Closure $onRestore = null;

    /** @var array<string, SnapshotDatabase> */
    private array $databases = [];

    /** @var array<string, array{tables: array, state: array}>|null a restored snapshot's databases, not yet written */
    private ?array $pending = null;

    /** reset() was called and nothing has touched the databases since. */
    private bool $resetPending = false;

    public function __construct(?string $run = null, ?string $directory = null)
    {
        $this->run = $run ?? (string) getenv(self::RUN);
        $this->directory = $directory ?? self::directoryOf($this->run);
    }

    /**
     * For a site's tests/bootstrap.php: one run id per test run, inherited by separate-process
     * tests. Only the process that made the id removes the run's snapshots at the end.
     */
    public static function bootstrap(): void
    {
        if (getenv(self::RUN) !== false) {
            return;
        }
        $run = getmypid() . '-' . bin2hex(random_bytes(4));
        putenv(self::RUN . '=' . $run);
        register_shutdown_function(static function () use ($run): void {
            $dir = self::directoryOf($run);
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($dir);
        });
    }

    public function enabled(): bool
    {
        return $this->run !== '' && getenv(self::SWITCH) !== '0';
    }

    /**
     * A directory that is this test's own (a temp dir with a random name). Its name is replaced by
     * the placeholder in the state, so another test's equal state under another name matches.
     */
    public function root(string $placeholder, string $directory): static
    {
        if ($directory !== '') {
            $this->roots[$placeholder] = rtrim($directory, '/');
        }

        return $this;
    }

    /** A path under a root ("{siteTmp}/error.log") nothing depends on: left out of the state and kept on restore. */
    public function ignore(string $path): static
    {
        $this->ignored[] = rtrim($path, '/');

        return $this;
    }

    /** Called after a restored step's files are in place, so the site drops what it booted before. */
    public function onRestore(\Closure $callback): static
    {
        $this->onRestore = $callback;

        return $this;
    }

    /** A database whose tables are part of the state and restored with the step's files; one or more. */
    public function database(string $name, SnapshotDatabase $db): static
    {
        $this->databases[$name] = $db;

        return $this;
    }

    /** Every database is emptied, when something next touches it (flush()). */
    public function reset(): void
    {
        $this->pending = null;
        $this->resetPending = true;
    }

    /** Writes what a restored step or reset() left pending; the site calls it before it touches a database. */
    public function flush(): void
    {
        if ($this->pending !== null) {
            $pending = $this->pending;
            $this->pending = null;
            foreach ($pending as $name => $database) {
                $this->databases[$name]->restore($database['tables']);
            }
        }
        if ($this->resetPending) {
            $this->resetPending = false;
            foreach ($this->databases as $db) {
                $db->empty();
            }
        }
    }

    /**
     * Runs $step, or restores what it left behind the last time it ran from this same state.
     *
     * @param callable(): mixed $step
     */
    public function step(string $name, callable $step): mixed
    {
        if (!$this->enabled()) {
            $this->flush();

            return $step();
        }

        $file = $this->directory . '/' . hash('xxh128', $name . "\0" . $this->state()) . '.ser';
        $stored = self::$memory[$file] ?? (is_file($file) ? (string) file_get_contents($file) : null);
        $snapshot = $stored !== null ? unserialize($stored) : null;
        if (is_array($snapshot)) {
            self::remember($file, $stored);
            $this->restoreFiles($snapshot['files']);
            // Written when something touches the databases: restored steps in a row write them once.
            $this->resetPending = false;
            $this->pending = $snapshot['databases'];
            if ($this->onRestore !== null) {
                ($this->onRestore)();
            }

            return $snapshot['result'];
        }

        $this->flush();
        $result = $step();
        $snapshot = $this->capture($result);
        if ($snapshot !== null) {
            $stored = serialize($snapshot);
            self::remember($file, $stored);
            $this->store($file, $stored);
        }

        return $result;
    }

    private static function directoryOf(string $run): string
    {
        return sys_get_temp_dir() . '/preflow_snapshots_' . $run;
    }

    private static function remember(string $file, string $snapshot): void
    {
        unset(self::$memory[$file]);
        self::$memory[$file] = $snapshot;
        if (count(self::$memory) > self::IN_MEMORY) {
            unset(self::$memory[array_key_first(self::$memory)]);
        }
    }

    /** Written to a temp file and renamed, so another process never reads half a snapshot. */
    private function store(string $file, string $snapshot): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }
        $tmp = $file . '.' . getmypid();
        file_put_contents($tmp, $snapshot);
        rename($tmp, $file);
    }

    /** Everything a step's outcome can depend on, the roots' random names left out. */
    private function state(): string
    {
        $env = ['getenv' => getenv(), '_ENV' => $_ENV];
        unset($env['getenv'][self::RUN], $env['_ENV'][self::RUN]);
        ksort($env['getenv']);
        ksort($env['_ENV']);

        return serialize([
            'env' => $this->withoutRoots(serialize($env)),
            'files' => array_map(static fn (string $content): string => hash('xxh128', $content), $this->files()),
            'databases' => $this->databaseStates(),
        ]);
    }

    /** @return array<string, array> by database: a pending snapshot's recorded state, none after reset(), else the live one */
    private function databaseStates(): array
    {
        $states = [];
        foreach ($this->databases as $name => $db) {
            $states[$name] = $this->pending[$name]['state'] ?? ($this->resetPending ? [] : $db->state());
        }

        return $states;
    }

    /** @return array|null null: the outcome names a root's real path or can't be stored, so no later test could use it */
    private function capture(mixed $result): ?array
    {
        try {
            $data = serialize($result);
        } catch (\Throwable) {
            return null;
        }
        $databases = [];
        foreach ($this->databases as $name => $db) {
            $databases[$name] = ['tables' => $db->capture(), 'state' => $db->state()];
            $data .= serialize($databases[$name]['tables']);
        }
        $files = $this->files();
        $contents = serialize(array_values($files));
        foreach ($this->roots as $placeholder => $root) {
            if (str_contains($data, $root) || str_contains($contents, $placeholder)) {
                return null;
            }
        }

        return ['files' => $files, 'databases' => $databases, 'result' => $result];
    }

    /** @return array<string, string> "{root}/path" => content ("{root}/dir/" => '' for a directory), roots' names left out */
    private function files(): array
    {
        $files = [];
        foreach ($this->roots as $placeholder => $root) {
            if (!is_dir($root)) {
                continue;
            }
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($rii as $f) {
                $path = $placeholder . substr($f->getPathname(), strlen($root));
                if ($this->isIgnored($path)) {
                    continue;
                }
                $files[$path . ($f->isDir() ? '/' : '')] = $f->isDir() ? '' : $this->withoutRoots((string) file_get_contents($f->getPathname()));
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Exact: what the roots hold now goes (children first), the snapshot's files are written.
     *
     * @param array<string, string> $files
     */
    private function restoreFiles(array $files): void
    {
        $real = fn (string $path): string => strtr($path, $this->roots);
        foreach (array_reverse(array_keys($this->files())) as $path) {
            if (!str_ends_with($path, '/')) {
                unlink($real($path));
            } elseif (!$this->holdsIgnored($path)) {
                rmdir($real($path));
            }
        }
        foreach ($files as $path => $content) {
            if (str_ends_with($path, '/')) {
                is_dir($real($path)) || mkdir($real($path), 0777, true);
                continue;
            }
            is_dir(dirname($real($path))) || mkdir(dirname($real($path)), 0777, true);
            file_put_contents($real($path), $content);
        }
    }

    private function isIgnored(string $path): bool
    {
        foreach ($this->ignored as $ignored) {
            if ($path === $ignored || str_starts_with($path, $ignored . '/')) {
                return true;
            }
        }

        return false;
    }

    /** A directory ("{root}/logs/") that holds an ignored path stays, with the ignored path in it. */
    private function holdsIgnored(string $dir): bool
    {
        foreach ($this->ignored as $ignored) {
            if (str_starts_with($ignored, $dir)) {
                return true;
            }
        }

        return false;
    }

    private function withoutRoots(string $value): string
    {
        return strtr($value, array_flip($this->roots));
    }
}
