<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

/**
 * Every temp root a Folio test makes goes through here: the path is registered and removed when
 * the test finishes (TempRootsExtension), and at the latest when the process ends, so a run
 * leaves the temp directory as it found it. Make roots with path() (nothing is created) or
 * directory() (created), never with sys_get_temp_dir() directly.
 *
 * A root belongs to the process that registered it. A forked child inherits the registry and the
 * shutdown sweep but removes only what it registered itself; otherwise it would delete the
 * parent's roots, and children exiting together would race to recreate the same session folder.
 *
 * @since 0.84.0
 */
final class TempRoots
{
    /** @var array<string, int> path => pid of the process that registered it */
    private static array $roots = [];
    /** @var array<string, int> removed already; swept once more at process end (a detached page-cache warmer can recreate files after its test) */
    private static array $removed = [];
    private static bool $shutdownRegistered = false;
    private static ?string $sessions = null;
    private static int $sessionsOwner = 0;

    /** A unique path under the temp directory, removed (file or tree) at the end of the test. */
    public static function path(string $prefix): string
    {
        return self::track(sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(5)));
    }

    /** A new, empty directory under the temp directory, removed at the end of the test. */
    public static function directory(string $prefix): string
    {
        $path = self::path($prefix);
        mkdir($path, 0777, true);

        return $path;
    }

    /**
     * PHP session files of the booted apps go to a folder of their own instead of the temp
     * directory, and are removed with the roots. Starts on first use, so a test run in a
     * separate process (which does not load the extension) gets it too.
     */
    public static function isolateSessions(): void
    {
        if ((self::$sessions !== null && self::$sessionsOwner === getmypid()) || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // In a forked child the inherited folder is the parent's: the child gets one of its own.
        self::$sessionsOwner = getmypid();
        self::$sessions = sys_get_temp_dir() . '/preflow_sessions_' . bin2hex(random_bytes(5));
        mkdir(self::$sessions, 0777, true);
        self::track(self::$sessions);
        session_save_path(self::$sessions);
    }

    /** Register an existing path (a file or a tree) for removal; returns it. */
    public static function track(string $path): string
    {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            // Registered from inside a shutdown function it runs after every shutdown function the apps
            // under test queued (the page cache and change log write at process end).
            register_shutdown_function(static function (): void {
                register_shutdown_function(self::sweep(...));
            });
        }
        self::$roots[$path] = getmypid();
        self::isolateSessions();

        return $path;
    }

    /** Remove every registered path. */
    public static function clean(): void
    {
        $me = getmypid();
        foreach (self::$roots as $path => $owner) {
            if ($owner !== $me) {
                continue;
            }
            self::remove($path);
            self::$removed[$path] = $owner;
            unset(self::$roots[$path]);
        }
        if (self::$sessions !== null && self::$sessionsOwner === $me) {
            // The session folder goes with the rest; the next test's sessions get it again.
            mkdir(self::$sessions, 0777, true);
            self::$roots[self::$sessions] = $me;
        }
    }

    /** Remove everything registered, and what was removed earlier but may have been written again. */
    public static function sweep(): void
    {
        self::clean();
        $me = getmypid();
        self::$sessions !== null && self::$sessionsOwner === $me && self::remove(self::$sessions);
        foreach (self::$removed as $path => $owner) {
            $owner === $me && self::remove($path);
        }
    }

    /** Remove $path: a file, a link or a whole tree (links are never followed). */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
