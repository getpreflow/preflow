<?php

declare(strict_types=1);

namespace Preflow\I18n;

/**
 * Translations by dot key ("group.area.name"). For each locale of the fallback chain (the
 * asked locale, then the fallback locale given to the constructor, then "en") the first
 * layer that has the key wins: an admin override (only when overrides are on and the
 * group's scope is "admin"), then the site's lang/{locale}/{group}.php key by key, then
 * the package's file (addDefaults(), usually through TranslationRegistry). A key found
 * nowhere comes back as the key itself.
 */
final class Translator
{
    private string $locale;

    /** @var array<string, string> group => 'admin'|'public' */
    private array $scopes = [];

    private TranslationOverrides $overrides;

    private bool $overridesOn = false;

    /** @var array<string, array<string, mixed>> Cache: "locale.group" => translations */
    private array $loaded = [];

    /** @var array<string, list<string>> group => lang directories of packages, in the order added */
    private array $defaults = [];

    public function __construct(
        private readonly string $langPath,
        string $locale,
        private readonly string $fallbackLocale,
    ) {
        $this->locale = $locale;
        $this->overrides = new NoTranslationOverrides();
    }

    /**
     * Translate a key with optional parameter replacement.
     *
     * @param array<string, string|int> $params Parameters to replace (:name => value)
     * @param string|null $locale another locale than the current one (a mail in the recipient's language)
     */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        $value = $this->lookup($key, $locale ?? $this->locale);

        return $value === null ? $key : $this->replaceParams($value, $params);
    }

    /**
     * Translate with pluralization.
     *
     * @param array<string, string|int> $params
     */
    public function choice(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $value = $this->lookup($key, $locale ?? $this->locale);
        if ($value === null) {
            return $key;
        }

        $resolved = (new PluralResolver())->resolve($value, $count);
        $params['count'] = (string) $count;

        return $this->replaceParams($resolved, $params);
    }

    /**
     * Whether the key has a text in exactly this locale (default: the current one), in any
     * layer: an override (when on and the group is admin), the site file or the package file.
     * No fallback to other locales.
     */
    public function has(string $key, ?string $locale = null): bool
    {
        return $this->valueIn($key, $locale ?? $this->locale) !== null;
    }

    /**
     * A package's own strings for a group: $langPath/{locale}/{group}.php. The site's
     * lang/{locale}/{group}.php is laid over them key by key, so a site changes single
     * phrases without copying the whole file.
     */
    public function addDefaults(string $group, string $langPath): void
    {
        $this->defaults[$group][] = rtrim($langPath, '/');
        foreach (array_keys($this->loaded) as $cacheKey) {
            if (str_ends_with($cacheKey, '.' . $group)) {
                unset($this->loaded[$cacheKey]);
            }
        }
    }

    /**
     * Register a package's files for a group only when nothing registered the group yet
     * (a declaration in the package's composer.json, extra.preflow.translations, normally
     * does). For apps without Composer metadata listing the package, e.g. test apps.
     *
     * @param 'admin'|'public' $scope
     */
    public function addDefaultsIfMissing(string $group, string $langPath, string $scope = 'admin'): void
    {
        if (isset($this->defaults[$group])) {
            return;
        }
        $this->addDefaults($group, $langPath);
        $this->scopes[$group] ??= $scope;
    }

    /**
     * Groups' scopes: 'admin' (admin interface, staff mails: overridable on admin requests)
     * or 'public'. Merged into the scopes set before.
     *
     * @param array<string, string> $scopes group => scope
     */
    public function setScopes(array $scopes): void
    {
        $this->scopes = array_replace($this->scopes, $scopes);
    }

    public function scope(string $group): ?string
    {
        return $this->scopes[$group] ?? null;
    }

    public function setOverrides(TranslationOverrides $overrides): void
    {
        $this->overrides = $overrides;
    }

    /** Overrides are off by default; Folio turns them on for admin requests. */
    public function useOverrides(bool $on): void
    {
        $this->overridesOn = $on;
    }

    /** Whether overrides are on now (so a caller that turns them on can restore the state it found). */
    public function usesOverrides(): bool
    {
        return $this->overridesOn;
    }

    /**
     * Every group with package files or a site file, sorted.
     *
     * @return list<string>
     */
    public function groups(): array
    {
        $groups = array_keys($this->defaults);
        foreach (glob($this->langPath . '/*/*.php') ?: [] as $file) {
            $groups[] = basename($file, '.php');
        }
        $groups = array_values(array_unique(array_map('strval', $groups)));
        sort($groups);

        return $groups;
    }

    /**
     * The locales with a file for the group (or for any group): package files and the
     * site's lang/, sorted.
     *
     * @return list<string>
     */
    public function locales(?string $group = null): array
    {
        $dirs = [$this->langPath];
        foreach ($group === null ? $this->defaults : [$this->defaults[$group] ?? []] as $paths) {
            array_push($dirs, ...$paths);
        }
        $locales = [];
        foreach ($dirs as $dir) {
            foreach (glob($dir . '/*/' . ($group ?? '*') . '.php') ?: [] as $file) {
                $locales[] = basename(dirname($file));
            }
        }
        $locales = array_values(array_unique($locales));
        sort($locales);

        return $locales;
    }

    /**
     * A group's texts in exactly one locale (site file over package file, no overrides, no
     * fallback), flattened to full dot keys: ['folio.common.save' => 'Save', ...].
     *
     * @return array<string, string>
     */
    public function entries(string $group, string $locale): array
    {
        $entries = [];
        self::flatten($this->loadGroup($locale, $group), $group, $entries);

        return $entries;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** The site's default locale, the second step of the fallback chain. */
    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /** The first text along the fallback chain: the locale, the fallback locale, then en. */
    private function lookup(string $key, string $locale): ?string
    {
        foreach (array_unique([$locale, $this->fallbackLocale, 'en']) as $candidate) {
            $value = $this->valueIn($key, $candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /** The text in exactly one locale: an override (when it applies), else the files. */
    private function valueIn(string $key, string $locale): ?string
    {
        if ($this->overridesOn && ($this->scopes[strstr($key, '.', true) ?: $key] ?? null) === 'admin') {
            $override = $this->overrides->get($locale, $key);
            if ($override !== null) {
                return $override;
            }
        }
        $value = $this->resolve($key, $locale);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed>          $data
     * @param array<string, string> $into
     */
    private static function flatten(array $data, string $prefix, array &$into): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::flatten($value, $prefix . '.' . $key, $into);
            } elseif (is_string($value)) {
                $into[$prefix . '.' . $key] = $value;
            }
        }
    }

    /**
     * Resolve a dot-notation key to its value.
     */
    private function resolve(string $key, string $locale): mixed
    {
        $parts = explode('.', $key, 2);
        $group = $parts[0];
        $subKey = $parts[1] ?? null;

        $translations = $this->loadGroup($locale, $group);

        if ($subKey === null) {
            return $translations[$group] ?? null;
        }

        // Support nested keys: "nested.deep"
        $segments = explode('.', $subKey);
        $current = $translations;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGroup(string $locale, string $group): array
    {
        $cacheKey = "{$locale}.{$group}";

        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $translations = [];
        foreach ([...($this->defaults[$group] ?? []), $this->langPath] as $dir) {
            $translations = array_replace_recursive($translations, self::read($dir . '/' . $locale . '/' . $group . '.php'));
        }
        $this->loaded[$cacheKey] = $translations;

        return $translations;
    }

    /** @return array<string, mixed> */
    private static function read(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $translations = require $path;

        return is_array($translations) ? $translations : [];
    }

    /**
     * @param array<string, string|int> $params
     */
    private function replaceParams(string $message, array $params): string
    {
        foreach ($params as $key => $value) {
            $message = str_replace(':' . $key, (string) $value, $message);
        }

        return $message;
    }
}
