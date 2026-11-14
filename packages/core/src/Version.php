<?php

declare(strict_types=1);

namespace Preflow\Core;

use Composer\InstalledVersions;

/**
 * The installed version of a Composer package, as Composer's runtime API (Composer\InstalledVersions)
 * knows it: for an admin footer, an about page, a support report.
 *
 *     Version::installed('preflow/folio'); // "0.75.0" (no leading "v"), "dev-main", or null
 *     Version::label('preflow/folio');     // "0.75.0", or "dev · 01c613d" for a dev install
 *
 * A dev install is a branch ("dev-main", "0.75.x-dev") or a package the root replaces, whose root
 * has no version ("No version set"). Its label is "dev" with the first seven characters of Composer's
 * reference when that is a hash (the commit of a VCS install, the root's for a replaced package). A
 * path repository (the monorepo, a local checkout: symlinked, or mirrored, which installed.json marks
 * with dist type "path") is plain "dev": its reference is Composer's content hash, not a commit, and
 * a version Composer guessed for it is no release. Null when the package is not installed, only
 * provided by another, or Composer's runtime API is not there.
 *
 * @since 0.75
 */
final class Version
{
    /** @var array<string, true>|null package names installed.json marks as path installs, read once */
    private static ?array $pathInstalls = null;

    /** The package's pretty version without a leading "v", or null when it is not installed. */
    public static function installed(string $package): ?string
    {
        $found = self::lookup($package);

        return $found === null ? null : self::withoutV($found['pretty']);
    }

    /** "0.75.0", "dev · 01c613d" or "dev"; null when the package is not installed. */
    public static function label(string $package): ?string
    {
        $found = self::lookup($package);

        if ($found === null) {
            return null;
        }

        return $found['path'] ? 'dev' : self::format($found['pretty'], $found['reference']);
    }

    /**
     * A Composer pretty version and reference as a label: a release as it is (without "v"), a dev
     * version as "dev · <first 7 of the reference>" ("dev" without a hash reference).
     */
    public static function format(?string $pretty, ?string $reference): ?string
    {
        if ($pretty === null || $pretty === '') {
            return null;
        }
        if (!self::isDev($pretty)) {
            return self::withoutV($pretty);
        }

        return $reference !== null && preg_match('/^[0-9a-f]{7,64}$/i', $reference) === 1
            ? 'dev · ' . strtolower(substr($reference, 0, 7))
            : 'dev';
    }

    /**
     * The packages vendor/composer/installed.json (next to the InstalledVersions class) lists with
     * dist type "path": mirrored path installs, which are no symlink. Read once per process.
     *
     * @return array<string, true>
     */
    private static function pathInstalls(): array
    {
        if (self::$pathInstalls !== null) {
            return self::$pathInstalls;
        }
        try {
            $paths = self::pathInstallsIn(dirname((string) (new \ReflectionClass(InstalledVersions::class))->getFileName()) . '/installed.json');
        } catch (\Throwable) {
            $paths = [];
        }

        return self::$pathInstalls = $paths;
    }

    /**
     * The packages an installed.json file lists with dist type "path" (Composer 2's {"packages": [...]}
     * or Composer 1's bare list); none when the file is missing or unreadable.
     *
     * @return array<string, true>
     */
    private static function pathInstallsIn(string $file): array
    {
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $packages = is_array($json) ? ($json['packages'] ?? $json) : [];
        $paths = [];
        foreach (is_array($packages) ? $packages : [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null) && ($package['dist']['type'] ?? null) === 'path') {
                $paths[$package['name']] = true;
            }
        }

        return $paths;
    }

    private static function isDev(string $pretty): bool
    {
        return str_starts_with($pretty, 'dev-')
            || str_ends_with($pretty, '-dev')
            || str_contains($pretty, 'No version set')
            || str_contains($pretty, 'no-version-set');
    }

    private static function withoutV(string $pretty): string
    {
        return preg_match('/^[vV]\d/', $pretty) === 1 ? substr($pretty, 1) : $pretty;
    }

    /**
     * The package's entry in Composer's installed data: its own version, or the root's when the root
     * replaces it (a monorepo). Null when not installed, only provided, or without Composer's API.
     *
     * @return array{pretty: string, reference: ?string, path: bool}|null
     */
    private static function lookup(string $package): ?array
    {
        if (!class_exists(InstalledVersions::class)) {
            return null;
        }
        foreach (InstalledVersions::getAllRawData() as $data) {
            $entry = $data['versions'][$package] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['pretty_version']) && is_string($entry['pretty_version'])) {
                $dir = is_string($entry['install_path'] ?? null) ? rtrim($entry['install_path'], '/') : '';

                return [
                    'pretty' => $entry['pretty_version'],
                    'reference' => is_string($entry['reference'] ?? null) ? $entry['reference'] : null,
                    'path' => ($dir !== '' && is_link($dir)) || isset(self::pathInstalls()[$package]),
                ];
            }
            $root = $data['root'] ?? [];
            if (isset($entry['replaced']) && is_string($root['pretty_version'] ?? null)) {
                return ['pretty' => $root['pretty_version'], 'reference' => is_string($root['reference'] ?? null) ? $root['reference'] : null, 'path' => false];
            }
        }

        return null;
    }
}
