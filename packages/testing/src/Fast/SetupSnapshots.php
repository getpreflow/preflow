<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * Runs a set-up step (migrate, seed) once per state and restores what it left behind for every
 * later test that starts from the same state. "The same state" is every environment variable and
 * the content of every file under the registered roots: anything a test changed before the step
 * makes it a different state, and the step runs for real.
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

    /** @var array<string, array> */
    private static array $memory = [];

    private readonly string $run;

    private readonly string $directory;

    /** @var array<string, string> placeholder => directory */
    private array $roots = [];

    /** @var list<string> paths like "{siteTmp}/error.log" */
    private array $ignored = [];

    private ?\Closure $onRestore = null;

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

    /**
     * Runs $step, or restores what it left behind the last time it ran from this same state.
     *
     * @param callable(): mixed $step
     */
    public function step(string $name, callable $step): mixed
    {
        if (!$this->enabled()) {
            return $step();
        }

        $file = $this->directory . '/' . hash('xxh128', $name . "\0" . $this->state()) . '.ser';
        $snapshot = self::$memory[$file] ?? (is_file($file) ? unserialize((string) file_get_contents($file)) : null);
        if (is_array($snapshot)) {
            self::remember($file, $snapshot);
            $this->restoreFiles($snapshot['files']);
            if ($this->onRestore !== null) {
                ($this->onRestore)();
            }

            return $snapshot['result'];
        }

        $result = $step();
        $snapshot = $this->capture($result);
        if ($snapshot !== null) {
            self::remember($file, $snapshot);
            $this->store($file, $snapshot);
        }

        return $result;
    }

    private static function directoryOf(string $run): string
    {
        return sys_get_temp_dir() . '/preflow_snapshots_' . $run;
    }

    private static function remember(string $file, array $snapshot): void
    {
        unset(self::$memory[$file]);
        self::$memory[$file] = $snapshot;
        if (count(self::$memory) > self::IN_MEMORY) {
            unset(self::$memory[array_key_first(self::$memory)]);
        }
    }

    /** Written to a temp file and renamed, so another process never reads half a snapshot. */
    private function store(string $file, array $snapshot): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }
        $tmp = $file . '.' . getmypid();
        file_put_contents($tmp, serialize($snapshot));
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
        ]);
    }

    /** @return array|null null: the outcome names a root's real path or can't be stored, so no later test could use it */
    private function capture(mixed $result): ?array
    {
        try {
            $data = serialize($result);
        } catch (\Throwable) {
            return null;
        }
        $files = $this->files();
        $contents = serialize(array_values($files));
        foreach ($this->roots as $placeholder => $root) {
            if (str_contains($data, $root) || str_contains($contents, $placeholder)) {
                return null;
            }
        }

        return ['files' => $files, 'result' => $result];
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
