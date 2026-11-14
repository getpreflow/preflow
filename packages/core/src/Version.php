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
 * A dev install is a branch ("dev-main", "0.75.x-dev"), a path repository (the monorepo, a local
 * checkout) or a package the root replaces, whose root has no version ("No version set"). Its label
 * is "dev" with the first seven characters of Composer's reference when it is a hash (a commit for
 * VCS installs; for path repositories Composer's own content hash). Null when the package is not
 * installed, only provided by another, or Composer's runtime API is not there.
 */
final class Version
{
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

        return $found === null ? null : self::format($found['pretty'], $found['reference']);
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
     * @return array{pretty: string, reference: ?string}|null
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
                return ['pretty' => $entry['pretty_version'], 'reference' => is_string($entry['reference'] ?? null) ? $entry['reference'] : null];
            }
            $root = $data['root'] ?? [];
            if (isset($entry['replaced']) && is_string($root['pretty_version'] ?? null)) {
                return ['pretty' => $root['pretty_version'], 'reference' => is_string($root['reference'] ?? null) ? $root['reference'] : null];
            }
        }

        return null;
    }
}
