<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Support;

/**
 * A temp copy of one of the monorepo's starters (packages/skeleton, packages/starter) set up as
 * after `composer require preflow/folio preflow/rbac`: its vendor/ links every entry of the
 * monorepo's vendor/, except vendor/autoload.php (the monorepo's loader plus the copy's App\
 * namespace) and vendor/composer/installed.json (only the packages such an app has, so a test
 * can leave rbac out or add roles). Nothing is installed per test; a copy costs a few
 * milliseconds.
 */
trait InstallTestApp
{
    /** Never copied: what composer, a test run or the app itself makes. */
    private const array NOT_COPIED = ['vendor', 'storage', 'tests', '.env', '.git', 'composer.dev.json', 'composer.dev.lock', 'composer.lock', '.phpunit.cache', '.phpunit.result.cache', 'bootstrap'];

    /** The preflow packages an app has after `composer require preflow/folio preflow/rbac` (plus what the starters require). */
    private const array PREFLOW = ['core', 'routing', 'view', 'twig', 'components', 'htmx', 'i18n', 'data', 'validation', 'form', 'devtools', 'testing', 'auth', 'blocks', 'publishing', 'media', 'notify', 'folio', 'rbac'];

    private static function monorepo(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * @param string $starter a folder under packages/ (skeleton, starter)
     * @param list<string> $without preflow package names to leave out (e.g. rbac)
     * @param list<string> $with more preflow package names (e.g. roles)
     */
    private function installApp(string $starter, array $without = [], array $with = []): string
    {
        $root = TempRoots::directory('folio_install_');
        $this->copyTree(self::monorepo() . '/packages/' . $starter, $root, true);
        foreach (['storage/cache', 'storage/logs', 'storage/data'] as $dir) {
            @mkdir($root . '/' . $dir, 0777, true);
        }
        $env = (string) file_get_contents($root . '/.env.example');
        $env = (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=folio-install-test-key-0123456789abcdef', $env);
        $env = (string) preg_replace('/^APP_URL=.*$/m', 'APP_URL=http://localhost:8080', $env);
        file_put_contents($root . '/.env', $env);

        $vendor = self::monorepo() . '/vendor';
        mkdir($root . '/vendor/composer', 0777, true);
        foreach (scandir($vendor) ?: [] as $entry) {
            if (!in_array($entry, ['.', '..', 'autoload.php', 'composer'], true)) {
                symlink($vendor . '/' . $entry, $root . '/vendor/' . $entry);
            }
        }
        $keep = array_merge(array_diff(self::PREFLOW, $without), $with);
        $installed = json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true);
        $installed['packages'] = array_values(array_filter(
            $installed['packages'],
            static fn (array $p): bool => !str_starts_with((string) $p['name'], 'preflow/') || in_array(substr((string) $p['name'], 8), $keep, true),
        ));
        // The extra of the monorepo's packages as their composer.json has it now (vendor's copy is from the last composer install).
        foreach ($installed['packages'] as $i => $package) {
            $composer = self::monorepo() . '/packages/' . substr((string) $package['name'], 8) . '/composer.json';
            if (str_starts_with((string) $package['name'], 'preflow/') && is_file($composer)) {
                $installed['packages'][$i]['extra'] = json_decode((string) file_get_contents($composer), true)['extra'] ?? [];
            }
        }
        $installed['dev-package-names'] = [];
        file_put_contents($root . '/vendor/composer/installed.json', json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/vendor/autoload.php', "<?php\n\n\$loader = require " . var_export($vendor . '/autoload.php', true) . ";\n"
            . "\$loader->addPsr4('App\\\\', dirname(__DIR__) . '/app/', true);\n\nreturn \$loader;\n");

        return $root;
    }

    private function copyTree(string $from, string $to, bool $top = false): void
    {
        @mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ($top && in_array($entry, self::NOT_COPIED, true))) {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_dir($source) && !is_link($source)) {
                $this->copyTree($source, $to . '/' . $entry);
            } else {
                copy($source, $to . '/' . $entry);
            }
        }
    }

    /**
     * Every file of the app outside vendor/ and storage/ (with $withStorage: also storage/data,
     * where a database would appear), path => bytes. Caches and logs a boot writes don't count.
     *
     * @return array<string, string>
     */
    private function files(string $root, bool $withStorage = false): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, 'storage/cache/') || str_starts_with($relative, 'storage/logs/')
                || (!$withStorage && str_starts_with($relative, 'storage/'))) {
                continue;
            }
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }
}
