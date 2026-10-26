<?php

declare(strict_types=1);

namespace Preflow\I18n\Console;

use Preflow\Core\Application;
use Preflow\DevTools\Command\CommandInterface;
use Preflow\I18n\DerivedKeys;
use Preflow\I18n\TranslationRegistry;
use Preflow\I18n\Translator;

/**
 * preflow translations:check [--group=<group>]: the release gate for "no raw keys".
 * Lists, per group and language, the keys that exist in English but not in that language
 * (site file and package file together; a package group is checked in every language any
 * package delivers, a site-only group in the languages it has files for), and the keys used as t('…') in package
 * templates and model files (of packages declaring translations) and in the site's
 * (app/, resources/, templates/, config/models/) that exist in no language. Keys derived
 * from other files (DerivedKeys: model files' labels, which Folio registers) are checked
 * for package groups only: each needs a text in every delivered language other than English,
 * from a language file or the file's own map; a site type's plain label is a valid fallback.
 * Exits 1 when anything is missing. The package lists it in composer.json (extra.preflow.commands).
 */
final class TranslationsCheckCommand implements CommandInterface
{
    /** Folders not scanned for used keys. */
    private const array SKIP = ['vendor', 'node_modules', 'tests', '.git'];

    /** @param (\Closure(): Application)|null $appFactory returns a booted application (tests) */
    public function __construct(private readonly ?\Closure $appFactory = null) {}

    public function getName(): string { return 'translations:check'; }
    public function getDescription(): string { return 'List translation keys missing in a language or used but defined nowhere [--group=<group>]'; }

    public function execute(array $args): int
    {
        $only = null;
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--group=')) {
                $only = substr($arg, 8);
            }
        }

        $app = $this->app();
        $container = $app->container();
        if (!$container->bound(Translator::class)) {
            echo "No translator: preflow/i18n is not booted.\n";

            return 1;
        }
        $translator = $container->get(Translator::class);
        $registry = $container->bound(TranslationRegistry::class) ? $container->get(TranslationRegistry::class) : new TranslationRegistry();

        $groups = $only !== null ? [$only] : $translator->groups();
        $locales = array_values(array_unique(['en', ...$translator->locales()]));
        $problems = 0;

        // Package groups (they have a scope: declared, or registered by their provider) must
        // cover every language any package delivers; a site-only group the languages it has files for.
        $delivered = [];
        foreach ($translator->groups() as $group) {
            if ($translator->scope($group) !== null) {
                array_push($delivered, ...$translator->locales($group));
            }
        }
        $delivered = array_values(array_unique($delivered));
        sort($delivered);

        foreach ($groups as $group) {
            $english = $translator->entries($group, 'en');
            $expected = $translator->scope($group) !== null
                ? array_unique([...$delivered, ...$translator->locales($group)])
                : $translator->locales($group);
            foreach ($expected as $locale) {
                if ($locale === 'en') {
                    continue;
                }
                $missing = array_keys(array_diff_key($english, $translator->entries($group, $locale)));
                if ($missing === []) {
                    continue;
                }
                $problems += count($missing);
                $whole = !in_array($locale, $translator->locales($group), true) ? ', no file for this language' : '';
                echo "Missing in {$locale} ({$group}, " . count($missing) . "{$whole}):\n";
                foreach ($missing as $key) {
                    echo "  {$key}\n";
                }
            }
        }

        $problems += $this->derived($container, $translator, $only, $delivered);

        $unknown = [];
        foreach ($this->usedKeys($app, $registry, $groups) as $key => $where) {
            if ($only !== null && !str_starts_with($key, $only . '.')) {
                continue;
            }
            foreach ($locales as $locale) {
                if ($translator->has($key, $locale)) {
                    continue 2;
                }
            }
            $unknown[$key] = $where;
        }
        if ($unknown !== []) {
            ksort($unknown);
            $problems += count($unknown);
            echo 'Used but defined nowhere (' . count($unknown) . "):\n";
            foreach ($unknown as $key => $where) {
                echo "  {$key}  ({$where})\n";
            }
        }

        if ($problems === 0) {
            echo 'Translations complete: ' . count($groups) . ' group(s), ' . implode(', ', $locales) . ".\n";

            return 0;
        }

        return 1;
    }

    /**
     * Derived keys of package groups missing in a delivered language (no language file text
     * and no entry in the source's map); prints them per locale and group. The number missing.
     *
     * @param list<string> $delivered
     */
    private function derived(\Preflow\Core\Container\Container $container, Translator $translator, ?string $only, array $delivered): int
    {
        if (!$container->bound(DerivedKeys::class)) {
            return 0;
        }
        $missing = [];
        foreach ($container->get(DerivedKeys::class)->all() as $key => ['value' => $value]) {
            $group = strstr($key, '.', true) ?: $key;
            if (($only !== null && $group !== $only) || $translator->scope($group) === null) {
                continue; // the site's own texts: the model file's plain text is enough
            }
            foreach (array_unique([...$delivered, ...$translator->locales($group)]) as $locale) {
                if ($locale === 'en' || $translator->has($key, $locale) || (is_array($value) && is_string($value[$locale] ?? null))) {
                    continue;
                }
                $missing[$locale][$group][] = $key;
            }
        }
        ksort($missing);
        $count = 0;
        foreach ($missing as $locale => $groups) {
            ksort($groups);
            foreach ($groups as $group => $keys) {
                sort($keys);
                $count += count($keys);
                echo "Missing in {$locale} (model labels of {$group}, " . count($keys) . "):\n";
                foreach ($keys as $key) {
                    echo "  {$key}\n";
                }
            }
        }

        return $count;
    }

    /**
     * t('key') uses in templates and model files: key => first "file:line".
     *
     * @param list<string> $groups
     * @return array<string, string>
     */
    private function usedKeys(Application $app, TranslationRegistry $registry, array $groups): array
    {
        $roots = [];
        foreach (['app', 'resources', 'templates', 'config/models'] as $sub) {
            $roots[] = $app->basePath($sub);
        }
        foreach ($groups as $group) {
            $path = $registry->packagePath($group);
            if ($path !== null) {
                $roots[] = $path;
            }
        }

        $used = [];
        foreach (array_unique($roots) as $root) {
            foreach (self::files($root) as $file) {
                $lines = file($file) ?: [];
                foreach ($lines as $i => $line) {
                    if (!preg_match_all('/(?<![\w$>:])t\(\s*([\'"])([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+)\1/', $line, $matches)) {
                        continue;
                    }
                    foreach ($matches[2] as $key) {
                        $used[$key] ??= self::relative($app, $file) . ':' . ($i + 1);
                    }
                }
            }
        }

        return $used;
    }

    /** @return list<string> templates (.twig, .blade.php) and model files (.json) under a folder */
    private static function files(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $f): bool => !($f->isDir() && in_array($f->getFilename(), self::SKIP, true)),
        ));
        foreach ($it as $file) {
            $name = $file->getFilename();
            if (str_ends_with($name, '.twig') || str_ends_with($name, '.blade.php')
                || (str_ends_with($name, '.json') && !in_array($name, ['composer.json', 'package.json', 'composer.lock'], true))) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private static function relative(Application $app, string $file): string
    {
        foreach (array_unique([$app->basePath(), (string) realpath($app->basePath())]) as $base) {
            $base = rtrim($base, '/') . '/';
            if ($base !== '/' && str_starts_with($file, $base)) {
                return substr($file, strlen($base));
            }
        }

        return $file;
    }

    private function app(): Application
    {
        if ($this->appFactory !== null) {
            return ($this->appFactory)();
        }

        $app = Application::create(getcwd() ?: '.');
        $app->boot();

        return $app;
    }
}
