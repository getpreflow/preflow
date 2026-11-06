<?php

declare(strict_types=1);

namespace Preflow\Testing\Fast;

/**
 * Runs a set-up step (migrate, seed) once per state and restores what it left behind for every
 * later test that starts from the same state. "The same state" is every environment variable, the
 * content of every file under the registered roots (and whether each root exists) and every
 * registered database's tables: anything a test changed before the step makes it a different state,
 * and the step runs for real. A restore puts back the step's files, roots and environment changes
 * exactly, or throws.
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

    /** A root's placeholder: a name in braces. */
    private const string PLACEHOLDER = '/^\{[A-Za-z0-9_.-]+\}$/';

    /** The placeholder a path under a root starts with. */
    private const string LEADING_PLACEHOLDER = '/^\{[A-Za-z0-9_.-]+\}(?=\/|$)/';

    /** Snapshots this process used last, by file (the rest are only in their files). */
    private const int IN_MEMORY = 4;

    /** @var array<string, string> serialized: every hit unserializes its own copy of the result */
    private static array $memory = [];

    private readonly string $run;

    private readonly string $directory;

    /** @var array<string, string|\Closure(): string> placeholder => directory, or a closure that returns it */
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
     *
     * $directory may be a closure that returns the directory: it is called every time the roots
     * are used, for a directory a test makes later (lazily). A closure that returns '' means there
     * is no such root right now. The placeholder is a name in braces: "{siteTmp}".
     *
     * @param string|\Closure(): string $directory
     */
    public function root(string $placeholder, string|\Closure $directory): static
    {
        if (preg_match(self::PLACEHOLDER, $placeholder) !== 1) {
            throw new \InvalidArgumentException("Snapshot root placeholder {$placeholder}: write it as a name in braces, like {siteTmp}.");
        }
        if (is_string($directory)) {
            $directory = rtrim($directory, '/');
            if ($directory === '') {
                throw new \InvalidArgumentException("Snapshot root {$placeholder}: the directory is empty. For a directory made later, pass a closure that returns it.");
            }
        }
        $this->roots[$placeholder] = $directory;

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
        $roots = $this->roots();
        $this->checkIgnored();
        if (!$this->enabled()) {
            $this->flush();

            return $step();
        }

        $file = $this->directory . '/' . hash('xxh128', $name . "\0" . $this->state($roots)) . '.ser';
        $stored = self::$memory[$file] ?? (is_file($file) ? (string) file_get_contents($file) : null);
        $snapshot = $stored !== null ? unserialize($stored) : null;
        if (is_array($snapshot)) {
            self::remember($file, $stored);
            $this->restoreFiles($snapshot['files'], $snapshot['roots'], $roots);
            self::applyEnvironment($snapshot['env'], $roots);
            // Written when something touches the databases: restored steps in a row write them once.
            $this->resetPending = false;
            $this->pending = $snapshot['databases'];
            if ($this->onRestore !== null) {
                ($this->onRestore)();
            }

            return $snapshot['result'];
        }

        $this->flush();
        $envBefore = $this->environment($roots);
        $result = $step();
        $snapshot = $this->capture($result, $roots, $envBefore);
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

    /**
     * The roots as they are right now: closures are called, a closure's '' leaves its root out.
     *
     * @return array<string, string> placeholder => directory
     */
    private function roots(): array
    {
        $roots = [];
        foreach ($this->roots as $placeholder => $directory) {
            if ($directory instanceof \Closure) {
                $directory = $directory();
                if (!is_string($directory)) {
                    throw new \LogicException("Snapshot root {$placeholder}: the closure must return a directory or ''.");
                }
                $directory = $directory === '' ? '' : rtrim($directory, '/');
                if ($directory === '') {
                    continue;
                }
            }
            foreach ($roots as $other => $otherDirectory) {
                if (str_starts_with($directory . '/', $otherDirectory . '/') || str_starts_with($otherDirectory . '/', $directory . '/')) {
                    throw new \LogicException("Snapshot roots {$other} ({$otherDirectory}) and {$placeholder} ({$directory}) overlap: one is inside the other. Register only the outer one.");
                }
            }
            $roots[$placeholder] = $directory;
        }

        return $roots;
    }

    /** Every ignored path starts with a registered root's placeholder (roots may be registered after ignore()). */
    private function checkIgnored(): void
    {
        foreach ($this->ignored as $ignored) {
            if (preg_match(self::LEADING_PLACEHOLDER, $ignored, $m) !== 1 || !array_key_exists($m[0], $this->roots)) {
                throw new \LogicException("Ignored path {$ignored} is under no registered snapshot root: write it as {root}/path, with {root} registered by root().");
            }
        }
    }

    /**
     * Everything a step's outcome can depend on, the roots' random names left out.
     *
     * @param array<string, string> $roots
     */
    private function state(array $roots): string
    {
        $exists = [];
        foreach (array_keys($this->roots) as $placeholder) {
            // null: no such root right now (a closure returned ''); false: the directory is missing.
            $exists[$placeholder] = isset($roots[$placeholder]) ? is_dir($roots[$placeholder]) : null;
        }

        return serialize([
            'env' => $this->environment($roots),
            'roots' => $exists,
            'files' => array_map(static fn (string $content): string => hash('xxh128', $content), $this->files($roots)),
            'databases' => $this->databaseStates(),
        ]);
    }

    /**
     * getenv() and $_ENV, the run id left out, the roots' paths as their placeholders.
     *
     * @param array<string, string> $roots
     * @return array{getenv: array<string, mixed>, _ENV: array<string, mixed>}
     */
    private function environment(array $roots): array
    {
        $env = ['getenv' => getenv(), '_ENV' => $_ENV];
        foreach ($env as $kind => $vars) {
            unset($vars[self::RUN]);
            ksort($vars);
            foreach ($vars as $var => $value) {
                if (is_string($value)) {
                    $vars[$var] = $this->withoutRoots($value, $roots);
                }
            }
            $env[$kind] = $vars;
        }

        return $env;
    }

    /**
     * What the step changed in the environment: variables set (new or changed) and removed.
     *
     * @param array{getenv: array<string, mixed>, _ENV: array<string, mixed>} $before
     * @param array{getenv: array<string, mixed>, _ENV: array<string, mixed>} $after
     * @return array<string, array{set: array<string, mixed>, removed: list<string>}>
     */
    private static function environmentDelta(array $before, array $after): array
    {
        $delta = [];
        foreach ($after as $kind => $vars) {
            $set = [];
            foreach ($vars as $var => $value) {
                if (!array_key_exists($var, $before[$kind]) || $before[$kind][$var] !== $value) {
                    $set[$var] = $value;
                }
            }
            $delta[$kind] = ['set' => $set, 'removed' => array_map('strval', array_keys(array_diff_key($before[$kind], $vars)))];
        }

        return $delta;
    }

    /**
     * A restored step's environment changes, its placeholders as the current roots.
     *
     * @param array<string, array{set: array<string, mixed>, removed: list<string>}> $delta
     * @param array<string, string> $roots
     */
    private static function applyEnvironment(array $delta, array $roots): void
    {
        $real = static fn (mixed $value): mixed => is_string($value) ? strtr($value, $roots) : $value;
        foreach ($delta['getenv']['set'] as $var => $value) {
            putenv($var . '=' . $real($value));
        }
        foreach ($delta['getenv']['removed'] as $var) {
            putenv($var);
        }
        foreach ($delta['_ENV']['set'] as $var => $value) {
            $_ENV[$var] = $real($value);
        }
        foreach ($delta['_ENV']['removed'] as $var) {
            unset($_ENV[$var]);
        }
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

    /**
     * @param array<string, string> $roots the roots before the step
     * @param array{getenv: array<string, mixed>, _ENV: array<string, mixed>} $envBefore
     * @return array|null null: the outcome names a root's real path, changes which directories the roots
     *                    are, or can't be stored, so no later test could use it
     */
    private function capture(mixed $result, array $roots, array $envBefore): ?array
    {
        if ($this->roots() !== $roots) {
            return null;
        }
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
        $files = $this->files($roots);
        $contents = serialize(array_values($files));
        $env = self::environmentDelta($envBefore, $this->environment($roots));
        // The values as the step set them: a placeholder written literally can't be told from a root.
        $rawEnv = '';
        foreach (array_keys($env['getenv']['set']) as $var) {
            $rawEnv .= "\0" . getenv($var);
        }
        foreach (array_keys($env['_ENV']['set']) as $var) {
            $rawEnv .= "\0" . serialize($_ENV[$var] ?? null);
        }
        foreach ($roots as $placeholder => $root) {
            if (str_contains($data, $root) || str_contains($contents, $placeholder) || str_contains($rawEnv, $placeholder)) {
                return null;
            }
        }
        $exists = array_map(is_dir(...), $roots);

        return ['files' => $files, 'roots' => $exists, 'env' => $env, 'databases' => $databases, 'result' => $result];
    }

    /**
     * @param array<string, string> $roots
     * @return array<string, string> "{root}/path" => content ("{root}/dir/" => '' for a directory), roots' names left out
     */
    private function files(array $roots): array
    {
        $files = [];
        foreach ($roots as $placeholder => $root) {
            if (!is_dir($root)) {
                continue;
            }
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($rii as $f) {
                if ($f->isLink()) {
                    throw new \LogicException("{$f->getPathname()} is a symlink: symlinks under a snapshot root aren't supported. Copy the file or register its target as a root.");
                }
                $path = $placeholder . substr($f->getPathname(), strlen($root));
                if ($this->isIgnored($path)) {
                    continue;
                }
                $files[$path . ($f->isDir() ? '/' : '')] = $f->isDir() ? '' : $this->withoutRoots((string) file_get_contents($f->getPathname()), $roots);
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * Exact: what the roots hold now goes (children first), the roots the step made or removed are
     * made or removed, the snapshot's files are written. Any failure throws, naming the path.
     *
     * @param array<string, string> $files
     * @param array<string, bool> $exists by placeholder: whether the root existed after the step
     * @param array<string, string> $roots
     */
    private function restoreFiles(array $files, array $exists, array $roots): void
    {
        $real = fn (string $path): string => self::realPath($path, $roots);
        error_clear_last();
        foreach (array_reverse(array_keys($this->files($roots))) as $path) {
            $file = $real($path);
            if (!str_ends_with($path, '/')) {
                self::check(@unlink($file), 'remove', $file);
            } elseif (!$this->holdsIgnored($path) || self::isEmptyDir($file)) {
                self::check(@rmdir($file), 'remove', $file);
            }
        }
        foreach ($roots as $placeholder => $root) {
            if (($exists[$placeholder] ?? false) && !is_dir($root)) {
                self::check(@mkdir($root, 0777, true), 'make', $root);
            } elseif (!($exists[$placeholder] ?? false) && is_dir($root) && self::isEmptyDir($root)) {
                self::check(@rmdir($root), 'remove', $root);
            }
        }
        foreach ($files as $path => $content) {
            $file = rtrim($real($path), '/');
            if (str_ends_with($path, '/')) {
                is_dir($file) || self::check(@mkdir($file, 0777, true), 'make', $file);
                continue;
            }
            is_dir(dirname($file)) || self::check(@mkdir(dirname($file), 0777, true), 'make', dirname($file));
            self::check(@file_put_contents($file, $content) !== false, 'write', $file);
        }
    }

    /**
     * "{root}/data/x" as a real path: only the leading placeholder is replaced.
     *
     * @param array<string, string> $roots
     */
    private static function realPath(string $path, array $roots): string
    {
        if (preg_match(self::LEADING_PLACEHOLDER, $path, $m) !== 1 || !isset($roots[$m[0]])) {
            throw new \RuntimeException("Snapshot restore: {$path} is under no current root.");
        }

        return $roots[$m[0]] . substr($path, strlen($m[0]));
    }

    private static function check(bool $done, string $what, string $path): void
    {
        if (!$done) {
            $error = error_get_last()['message'] ?? 'failed';
            error_clear_last();
            throw new \RuntimeException("Snapshot restore could not {$what} {$path}: {$error}");
        }
    }

    private static function isEmptyDir(string $dir): bool
    {
        return is_dir($dir) && (new \FilesystemIterator($dir))->valid() === false;
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

    /**
     * Each root's path as its placeholder, where it stands as a whole path (followed by "/" or by
     * something that can't continue a file name), so "/tmp/ab" isn't taken for root "/tmp/a" + "b".
     *
     * @param array<string, string> $roots
     */
    private function withoutRoots(string $value, array $roots): string
    {
        if ($roots === []) {
            return $value;
        }
        $byLength = $roots;
        uasort($byLength, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        $pattern = '#(?:' . implode('|', array_map(static fn (string $root): string => preg_quote($root, '#'), $byLength)) . ')(?![A-Za-z0-9._~-])#';
        $placeholders = array_flip($roots);

        $replaced = preg_replace_callback($pattern, static fn (array $m): string => $placeholders[$m[0]], $value);
        if ($replaced === null) {
            // Never an empty string in the state for content that couldn't be read.
            throw new \RuntimeException('Snapshot state: replacing the roots failed (' . preg_last_error_msg() . ').');
        }

        return $replaced;
    }
}
