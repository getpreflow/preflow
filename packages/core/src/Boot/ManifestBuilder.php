<?php

declare(strict_types=1);

namespace Preflow\Core\Boot;

use Preflow\Core\Application;
use Preflow\Core\Composer\InstalledPackages;

/**
 * Builds the boot manifest (`preflow optimize`): boots a fresh application for the base path as
 * a web request would (outside the console mode), with a recording BootManifest, reads every
 * config file, runs the producers' warmers, and writes what they remembered with the signatures
 * of their sources. Also reports drift (`optimize --check`).
 */
final class ManifestBuilder
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Builds and writes the manifest. With debug on (app.debug) none is written and an existing
     * one is removed: boot ignores a manifest in debug mode anyway. A failed build or write also
     * removes an existing one (and rethrows): web requests boot live rather than from the old code's.
     *
     * @return array{written: bool, file: string, skipped: list<string>, catalogs: list<string>, routes: list<string>, config: list<string>, reason: ?string}
     */
    public function write(): array
    {
        $base = $this->absoluteBase();
        try {
            $data = $this->build();
        } catch (\Throwable $e) {
            ManifestFile::forget($base); // never leave the manifest of the code before in place
            throw $e;
        }
        $file = ManifestFile::path($base);
        $recorded = $data['__recorded'];
        unset($data['__recorded']);

        if ((int) ($data['config']['app']['debug'] ?? 0) !== 0) {
            ManifestFile::forget($base);

            return ['written' => false, 'file' => $file, 'skipped' => [], 'catalogs' => [], 'routes' => [], 'config' => [], 'reason' => 'APP_DEBUG is on: boot never uses a manifest in debug mode (an existing one was removed).'];
        }

        try {
            ManifestFile::write($base, $data);
        } catch (\Throwable $e) {
            ManifestFile::forget($base); // the same after a failed write: not the old manifest
            throw $e;
        }

        return [
            'written' => true,
            'file' => $file,
            'skipped' => $recorded['skipped'],
            'catalogs' => array_keys($data['catalogs']),
            'routes' => array_keys($data['routes']),
            'config' => array_keys($data['config']),
            'reason' => null,
        ];
    }

    /**
     * The manifest's array, as write() would store it (plus '__recorded').
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $base = $this->absoluteBase();
        $manifest = BootManifest::recording();

        $app = Application::create($base);
        $app->setRunningInConsole(false);
        $app->useBootManifest($manifest);
        $app->boot();
        // Every config file, also those no boot step read: the manifest holds the merged config.
        foreach (glob($base . '/config/*.php') ?: [] as $file) {
            $app->configFile(basename($file, '.php'));
        }
        $manifest->warm($app);
        $packageRuntime = self::packageRuntime($app->installedPackages());

        $recorded = $manifest->recorded();

        // Core sources: the config files, the .env, Composer's package list, the files packages check per request.
        $sources = [];
        $packageRuntimePaths = array_map(static fn (string $path): string => $base . '/' . $path, $packageRuntime);
        foreach ([$base . '/config', $base . '/.env', $base . '/vendor/composer/installed.json', ...$packageRuntimePaths, ...$recorded['sources']] as $path) {
            $sources[self::relative($base, $path)] = ManifestFile::signature($path);
        }
        ksort($sources);

        $runtime = [];
        foreach ([...ManifestFile::CORE_RUNTIME, ...$packageRuntime] as $source) {
            $runtime[$source] = ManifestFile::stamp($base . '/' . $source);
        }
        $keys = ManifestFile::envKeys($base . '/.env');
        $runtime['env'] = ['keys' => $keys, 'hash' => ManifestFile::envHash($keys)];

        return [
            'version' => ManifestFile::VERSION,
            'built' => date(DATE_ATOM),
            'sources' => $sources,
            'runtime' => $runtime,
            'config' => $recorded['config'],
            'packages' => $recorded['packages'],
            'catalogs' => $recorded['catalogs'],
            'routes' => $recorded['routes'],
            '__recorded' => $recorded,
        ];
    }

    /**
     * What changed since the manifest was written: one line per source whose signature differs
     * (or that is gone), plus the runtime-checked sources. Null when there is no manifest.
     *
     * @return list<string>|null
     */
    public function drift(): ?array
    {
        $base = $this->absoluteBase();
        $file = ManifestFile::path($base);
        if (!is_file($file)) {
            return null;
        }
        try {
            $data = include $file;
        } catch (\Throwable) {
            return ['the manifest does not load; run preflow optimize'];
        }
        if (!is_array($data) || ($data['version'] ?? null) !== ManifestFile::VERSION) {
            return ['the manifest has another version; run preflow optimize'];
        }

        $missing = ManifestFile::missingCoreRuntime($data['runtime'] ?? []);
        if ($missing !== null) {
            return ["the manifest lacks the checked source {$missing}; run preflow optimize"];
        }

        // One line per source: a signed source that is also checked per request (.env, installed.json) once.
        $drift = [];
        foreach ((array) ($data['sources'] ?? []) as $path => $signature) {
            $now = ManifestFile::signature(self::absolute($base, (string) $path));
            if ($now !== $signature) {
                $drift[(string) $path] = match (true) {
                    $now === '-' => 'removed',
                    $signature === '-' => 'added',
                    default => 'changed',
                };
            }
        }
        foreach ((array) ($data['runtime'] ?? []) as $source => $stamp) {
            if ($source !== 'env' && $stamp !== ManifestFile::stamp($base . '/' . $source)) {
                $drift[$source] = ($drift[$source] ?? 'changed') . ' (checked per request: web requests boot live until preflow optimize)';
            }
        }
        $drift = array_map(static fn (int|string $path, string $what): string => "{$path}: {$what}", array_keys($drift), $drift);
        \Preflow\Core\EnvLoader::load($base . '/.env'); // as a web request's boot does (it never overrides a set variable)
        $env = $data['runtime']['env'] ?? null;
        $keys = ManifestFile::envKeys($base . '/.env');
        if (!is_array($env) || ($env['keys'] ?? null) !== $keys || ($env['hash'] ?? null) !== ManifestFile::envHash($keys)) {
            $drift[] = 'environment: a .env variable differs (checked per request: web requests boot live until preflow optimize)';
        }

        return array_values(array_unique($drift));
    }

    /**
     * The project-relative files installed packages want checked on every request
     * (composer `extra.preflow.runtime`: a list of relative paths), without the core's,
     * deduplicated and sorted.
     *
     * @param list<array<string, mixed>> $packages
     * @return list<string>
     */
    private static function packageRuntime(array $packages): array
    {
        $paths = [];
        foreach ($packages as $package) {
            $declared = InstalledPackages::extra($package, 'preflow.runtime');
            if ($declared === null) {
                continue;
            }
            $name = (string) ($package['name'] ?? '') !== '' ? (string) $package['name'] : '?';
            if (!is_array($declared) || !array_is_list($declared)) {
                throw new \RuntimeException("Package {$name}: extra.preflow.runtime must be a list of relative paths.");
            }
            foreach ($declared as $path) {
                if (!is_string($path) || $path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || $path === 'env') {
                    throw new \RuntimeException("Package {$name}: extra.preflow.runtime lists " . (is_string($path) && $path !== '' ? "\"{$path}\", which is not a relative path without \"..\" (and \"env\" is reserved)" : 'a value that is not a non-empty path') . '.');
                }
                if (!in_array($path, ManifestFile::CORE_RUNTIME, true)) {
                    $paths[$path] = true;
                }
            }
        }
        $paths = array_keys($paths);
        sort($paths);

        return $paths;
    }

    private function absoluteBase(): string
    {
        $base = rtrim($this->basePath, '/');
        if (!str_starts_with($base, '/')) {
            $base = rtrim((getcwd() ?: '') . '/' . $base, '/');
        }

        return realpath($base) ?: $base;
    }

    private static function relative(string $base, string $path): string
    {
        $real = realpath($path);
        foreach (array_unique([$path, $real === false ? $path : $real]) as $candidate) {
            if (str_starts_with($candidate, $base . '/')) {
                return substr($candidate, strlen($base) + 1);
            }
        }

        return $path; // outside the project (e.g. a models path elsewhere): kept absolute
    }

    private static function absolute(string $base, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $base . '/' . $path;
    }
}
