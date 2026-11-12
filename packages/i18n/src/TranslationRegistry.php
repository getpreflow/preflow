<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Preflow\Core\Composer\InstalledPackages;

/**
 * The translation groups installed packages bring. A package declares them in its
 * composer.json, read from Composer's vendor/composer/installed.json (like
 * extra.preflow.commands):
 *
 *     "extra": { "preflow": { "translations": {
 *         "media": { "path": "lang", "scope": "admin" },
 *         "members": { "path": "lang", "scope": "public" },
 *         "codes": "lang"
 *     } } }
 *
 * The key is the group (keys read "media.assets.replace"); path is the folder holding
 * {locale}/{group}.php, relative to the package root; scope is "admin" (admin interface,
 * staff mails: overridable by admins) or "public". A plain string is the path with scope
 * "admin". A bad declaration is skipped with an error_log line; it never breaks boot.
 */
final class TranslationRegistry
{
    public const array SCOPES = ['admin', 'public'];

    /**
     * @param array<string, array{path: string, scope: 'admin'|'public'}> $groups
     * @param array<string, string>                                        $packagePaths group => package root
     */
    public function __construct(
        private readonly array $groups = [],
        private readonly array $packagePaths = [],
    ) {}

    /** @param string|null $cacheDir where the decoded installed.json is cached (see InstalledPackages) */
    public static function fromInstalled(string $installedJson, ?string $cacheDir = null): self
    {
        return self::fromPackages($installedJson, InstalledPackages::read($installedJson, $cacheDir));
    }

    /**
     * From the packages InstalledPackages::read() gives (the boot manifest keeps them).
     *
     * @param string                          $installedJson the installed.json they come from (install paths are relative to its folder)
     * @param list<array<string, mixed>>      $packages
     */
    public static function fromPackages(string $installedJson, array $packages): self
    {
        $groups = [];
        $roots = [];
        foreach ($packages as $package) {
            $declared = InstalledPackages::extra($package, 'preflow.translations');
            if ($declared === null) {
                continue;
            }
            $name = $package['name'] !== '' ? $package['name'] : '?';
            if (!is_array($declared)) {
                self::warn("package {$name} declares extra.preflow.translations as something other than a map; skipped.");
                continue;
            }
            $root = self::packageRoot($installedJson, $package);
            foreach ($declared as $group => $spec) {
                $group = (string) $group;
                [$path, $scope] = match (true) {
                    is_string($spec) => [$spec, 'admin'],
                    is_array($spec) => [$spec['path'] ?? null, $spec['scope'] ?? 'admin'],
                    default => [null, null],
                };
                if (preg_match('/^[a-z0-9_-]+$/i', $group) !== 1) {
                    self::warn("package {$name} declares translation group \"{$group}\" (letters, digits, _ and - only); skipped.");
                    continue;
                }
                if (!is_string($path) || $path === '') {
                    self::warn("package {$name} declares translation group \"{$group}\" without a path; skipped.");
                    continue;
                }
                if (!in_array($scope, self::SCOPES, true)) {
                    self::warn("package {$name} declares translation group \"{$group}\" with scope \"" . (is_scalar($scope) ? (string) $scope : get_debug_type($scope)) . '" (admin or public); skipped.');
                    continue;
                }
                $dir = $root . '/' . trim($path, '/');
                if (!is_dir($dir)) {
                    self::warn("package {$name} declares translation group \"{$group}\" in {$dir}, which does not exist; skipped.");
                    continue;
                }
                $real = realpath($dir);
                if ($real === false || !str_starts_with($real . '/', $root . '/')) {
                    self::warn("package {$name} declares translation group \"{$group}\" in {$dir}, outside the package; skipped.");
                    continue;
                }
                $dir = $real;
                if (isset($groups[$group])) {
                    self::warn("package {$name} declares translation group \"{$group}\", which another package declared first; skipped.");
                    continue;
                }
                $groups[$group] = ['path' => $dir, 'scope' => $scope];
                $roots[$group] = $root;
            }
        }

        return new self($groups, $roots);
    }

    /**
     * The registry as plain data (the boot manifest stores it), for fromArray().
     *
     * @return array{groups: array<string, array{path: string, scope: 'admin'|'public'}>, paths: array<string, string>}
     */
    public function toArray(): array
    {
        return ['groups' => $this->groups, 'paths' => $this->packagePaths];
    }

    /** @param array{groups?: mixed, paths?: mixed} $data toArray()'s */
    public static function fromArray(array $data): self
    {
        return new self((array) ($data['groups'] ?? []), (array) ($data['paths'] ?? []));
    }

    /** @return array<string, array{path: string, scope: 'admin'|'public'}> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** @return 'admin'|'public'|null */
    public function scope(string $group): ?string
    {
        return $this->groups[$group]['scope'] ?? null;
    }

    /** The root of the package that declared the group (for scanning its templates). */
    public function packagePath(string $group): ?string
    {
        return $this->packagePaths[$group] ?? null;
    }

    /** Registers every group's files with the translator, and their scopes. */
    public function apply(Translator $translator): void
    {
        foreach ($this->groups as $group => $spec) {
            $translator->addDefaults($group, $spec['path']);
        }
        $translator->setScopes(array_map(static fn (array $spec): string => $spec['scope'], $this->groups));
    }

    /** @param array<string, mixed> $package */
    private static function packageRoot(string $installedJson, array $package): string
    {
        $composerDir = dirname($installedJson);
        $installPath = $package['install-path'] ?? null;
        $root = is_string($installPath) && $installPath !== ''
            ? (str_starts_with($installPath, '/') ? $installPath : $composerDir . '/' . $installPath)
            : dirname($composerDir) . '/' . (string) ($package['name'] ?? '');

        return rtrim(realpath($root) ?: $root, '/');
    }

    private static function warn(string $message): void
    {
        error_log('Preflow i18n: ' . $message);
    }
}
