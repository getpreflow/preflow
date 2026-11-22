<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Context\RenderContext;

/**
 * Translations by dot key ("group.area.name"). For each locale of the fallback chain (the
 * asked locale, then the fallback locale given to the constructor, then "en") the first
 * layer that has the key wins: an override (when overrides are on and the group's scope is
 * "admin", or the key is under a prefix made overridable(); always for a group made
 * alwaysOverride()), then the site's
 * lang/{locale}/{group}.php key by key, then
 * the package's file (addDefaults(), usually through TranslationRegistry). A key found
 * nowhere comes back as the key itself.
 *
 * Since 0.81 the locale and the overrides switch come from the render context
 * (Contexts::current()): on the public surface the texts are in the context's locale with
 * overrides off; on the admin surface they are in its interface locale, with the overrides the
 * surface asks for. Outside every render (a script, a unit test) the translator uses its own
 * default: the locale given to the constructor and overrides off. The texts a context resolved
 * are kept per text locale and overrides switch, so every context with the same two (a loop that
 * builds a new context per item, within() back and forth) shares them; keys an override can
 * answer are always asked again.
 */
final class Translator
{
    /** Outside every render: the locale the texts are in (the constructor's, or setLocale() there). */
    private string $locale;

    /** @var array<string, string> group => 'admin'|'public' */
    private array $scopes = [];

    private TranslationOverrides $overrides;

    /** Outside every render: whether overrides are on (useOverrides() there). */
    private bool $overridesOn = false;

    /**
     * The text caches, one per text locale and overrides switch (all a render context changes in
     * how a key resolves): "{locale}" or "{locale}\x01" (overrides on) => its texts, for keys no
     * override can answer. Contexts with the same two share one; forgetMemos() drops them all.
     *
     * @var array<string, CatalogueMemo>
     */
    private array $memos = [];

    /** @var list<string> key prefixes of groups without a scope that take overrides too (overridable()) */
    private array $overridablePrefixes = [];

    /** @var array<string, true> groups whose overrides apply on every request (alwaysOverride()) */
    private array $alwaysOverridden = [];

    /** @var array<string, array<string, mixed>> Cache: "locale.group" => translations (the files; the same for every context) */
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
        $value = $this->lookup($key, $locale);

        return $value === null ? $key : $this->replaceParams($value, $params);
    }

    /**
     * Translate with pluralization.
     *
     * @param array<string, string|int> $params
     */
    public function choice(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $value = $this->lookup($key, $locale);
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
        [$current, $overrides] = $this->current();

        return $this->valueIn($key, $locale ?? $current, $this->overrideApplies($key, $overrides)) !== null;
    }

    /**
     * A package's own strings for a group: $langPath/{locale}/{group}.php. The site's
     * lang/{locale}/{group}.php is laid over them key by key, so a site changes single
     * phrases without copying the whole file.
     */
    public function addDefaults(string $group, string $langPath): void
    {
        $this->defaults[$group][] = rtrim($langPath, '/');
        $this->forgetMemos();
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
        $this->keepAdminGroupsAdminOnly();
        $this->forgetMemos();
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
        $this->keepAdminGroupsAdminOnly();
        $this->forgetMemos();
    }

    /** A group declared "admin" after alwaysOverride() (its package registered later) is no longer always overridden. */
    private function keepAdminGroupsAdminOnly(): void
    {
        foreach (array_keys($this->alwaysOverridden) as $group) {
            if (($this->scopes[$group] ?? null) === 'admin') {
                unset($this->alwaysOverridden[$group]);
            }
        }
    }

    public function scope(string $group): ?string
    {
        return $this->scopes[$group] ?? null;
    }

    public function setOverrides(TranslationOverrides $overrides): void
    {
        $this->overrides = $overrides;
        $this->forgetMemos();
    }

    /** The override layer in use (NoTranslationOverrides until one is set). */
    public function overrides(): TranslationOverrides
    {
        return $this->overrides;
    }

    /**
     * Keys under this prefix ("site.types.") take overrides too, although their group has no
     * scope: admin-only texts in a site's own group (model labels). Never for a public group.
     */
    public function overridable(string $prefix): void
    {
        if ($prefix !== '' && !in_array($prefix, $this->overridablePrefixes, true)) {
            $this->overridablePrefixes[] = $prefix;
            $this->forgetMemos();
        }
    }

    /**
     * The group's overrides apply on every request, whether useOverrides() is on or off: texts
     * of a site group that staff edit and that public pages or mails render. Not for an admin
     * group (those stay admin-only), also not when the group is declared "admin" later. Calling
     * it again changes nothing.
     *
     * @throws \InvalidArgumentException for an empty group or a group of scope "admin"
     */
    public function alwaysOverride(string $group): void
    {
        if ($group === '') {
            throw new \InvalidArgumentException('A group name is required.');
        }
        if (($this->scopes[$group] ?? null) === 'admin') {
            throw new \InvalidArgumentException("The group \"{$group}\" is an admin group; its overrides stay admin-only.");
        }
        $this->alwaysOverridden[$group] = true;
        $this->forgetMemos();
    }

    /**
     * The groups made alwaysOverride(), sorted.
     *
     * @return list<string>
     */
    public function alwaysOverridden(): array
    {
        $groups = array_map('strval', array_keys($this->alwaysOverridden));
        sort($groups);

        return $groups;
    }

    /** Whether an override can apply to the key: an admin group's (when overrides are on), under an overridable() prefix of a group without scope, or in an alwaysOverride() group. */
    public function isOverridable(string $key): bool
    {
        $group = strstr($key, '.', true) ?: $key;
        if (isset($this->alwaysOverridden[$group])) {
            return true;
        }
        $scope = $this->scopes[$group] ?? null;
        if ($scope !== null) {
            return $scope === 'admin';
        }
        foreach ($this->overridablePrefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns overrides on or off for the rest of the current render: on narrows its frame to the
     * admin surface with overrides, in the locale the texts are in now; off narrows an admin
     * surface to one without (on the public surface they are off already). Outside every render
     * it switches the translator's own default.
     *
     * @deprecated 0.81, removed in 0.83: narrow the frame to the surface, Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale, $overrides))
     */
    public function useOverrides(bool $on): void
    {
        Deprecations::once('Preflow\I18n\Translator::useOverrides() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->admin($uiLocale, $overrides)).');
        if (Contexts::depth() === 0) {
            $this->overridesOn = $on;
            $this->forgetMemos();

            return;
        }
        Contexts::narrow(static function (RenderContext $ctx) use ($on): RenderContext {
            $surface = $ctx->surface;
            if (!$surface->admin) {
                return $on ? $ctx->admin($ctx->locale, true) : $ctx;
            }

            return $surface->overrides === $on ? $ctx : $ctx->admin((string) $surface->uiLocale, $on);
        });
    }

    /** Whether overrides are on now: the current render's surface asks for them (outside every render, the translator's own default). */
    public function usesOverrides(): bool
    {
        return $this->current()[1];
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
     * The languages the site offers: the locales with any file in the site's own lang/, sorted.
     *
     * @return list<string>
     */
    public function siteLocales(): array
    {
        $locales = [];
        foreach (glob($this->langPath . '/*/*.php') ?: [] as $file) {
            $locales[] = basename(dirname($file));
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

    /**
     * A group's texts from the packages' files only, in exactly one locale, flattened.
     *
     * @return array<string, string>
     */
    public function packageEntries(string $group, string $locale): array
    {
        return $this->layerEntries($group, $locale, $this->defaults[$group] ?? []);
    }

    /**
     * A group's texts from the site's lang/{locale}/{group}.php only, flattened.
     *
     * @return array<string, string>
     */
    public function siteEntries(string $group, string $locale): array
    {
        return $this->layerEntries($group, $locale, [$this->langPath]);
    }

    /**
     * @param list<string> $dirs
     * @return array<string, string>
     */
    private function layerEntries(string $group, string $locale, array $dirs): array
    {
        $translations = [];
        foreach ($dirs as $dir) {
            $translations = array_replace_recursive($translations, self::read($dir . '/' . $locale . '/' . $group . '.php'));
        }
        $entries = [];
        self::flatten($translations, $group, $entries);

        return $entries;
    }

    /**
     * The locale the texts are in now: the current render's locale on the public surface, its
     * interface locale on the admin surface; outside every render the translator's own default.
     */
    public function getLocale(): string
    {
        return $this->current()[0];
    }

    /**
     * Sets the locale the texts are in for the rest of the current render: narrows its frame to
     * $locale (on the admin surface, to that interface locale, which is what getLocale() gives
     * there). Outside every render it sets the translator's own default.
     *
     * @deprecated 0.81, removed in 0.83: Contexts::narrow(fn ($ctx) => $ctx->in($locale)) for the
     *             rest of the render, Contexts::within($ctx->in($locale), $fn) for a stretch of it;
     *             on the admin surface $ctx->admin($locale) in place of $ctx->in($locale)
     */
    public function setLocale(string $locale): void
    {
        Deprecations::once('Preflow\I18n\Translator::setLocale() is deprecated since 0.81 and is removed in 0.83; use Contexts::narrow(fn ($ctx) => $ctx->in($locale)), or on the admin surface Contexts::narrow(fn ($ctx) => $ctx->admin($locale)).');
        if (Contexts::depth() === 0) {
            $this->locale = $locale;
            $this->forgetMemos();

            return;
        }
        Contexts::narrow(static fn (RenderContext $ctx): RenderContext => $ctx->surface->admin
            ? $ctx->admin($locale, $ctx->surface->overrides)
            : $ctx->in($locale));
    }

    /** The site's default locale, the second step of the fallback chain. */
    public function getFallbackLocale(): string
    {
        return $this->fallbackLocale;
    }

    /**
     * The text locale and the overrides switch now: the current render's (with the text cache for
     * those two), else the translator's own default.
     *
     * @return array{string, bool, CatalogueMemo|null} the locale, overrides on, the current render's cache (null outside every render)
     */
    private function current(): array
    {
        $ctx = Contexts::peek();
        if ($ctx === null) {
            return [$this->locale, $this->overridesOn, null];
        }
        $memo = $this->memoOf($ctx);

        return [$memo->locale, $memo->overrides, $memo];
    }

    /**
     * The text cache of $ctx, which names its text locale and overrides switch: on the admin
     * surface its interface locale and the overrides it asks for, else the context's locale.
     * Only these two change how a key resolves (the fallback chain and the layers are the
     * translator's own; the surface counts through these two), so contexts that agree on them
     * share one cache.
     */
    private function memoOf(RenderContext $ctx): CatalogueMemo
    {
        $surface = $ctx->surface;
        $locale = $surface->admin ? (string) $surface->uiLocale : $ctx->locale;
        $overrides = $surface->overrides;

        return $this->memos[$overrides ? $locale . "\x01" : $locale] ??= new CatalogueMemo($locale, $overrides);
    }

    /** Drops every context's text cache: what resolves a key changed (files, scopes, overrides, the default). */
    private function forgetMemos(): void
    {
        $this->memos = [];
    }

    /**
     * The first text along the fallback chain: the locale (default: the current one), the fallback
     * locale, then en. Inside a render a key no override can answer is kept for its context.
     */
    private function lookup(string $key, ?string $locale): ?string
    {
        // current() without its array: this runs for every t().
        $ctx = Contexts::peek();
        if ($ctx === null) {
            $memo = null;
            $current = $this->locale;
            $overrides = $this->overridesOn;
        } else {
            $memo = $this->memoOf($ctx);
            $current = $memo->locale;
            $overrides = $memo->overrides;
        }
        $locale ??= $current;
        $memoKey = $locale . "\0" . $key;
        if ($memo !== null && array_key_exists($memoKey, $memo->texts)) {
            return $memo->texts[$memoKey];
        }

        $applies = $this->overrideApplies($key, $overrides);
        $found = null;
        foreach (array_unique([$locale, $this->fallbackLocale, 'en']) as $candidate) {
            $value = $this->valueIn($key, $candidate, $applies);
            if ($value !== null) {
                $found = $value;
                break;
            }
        }
        if ($memo !== null && !$applies) {
            $memo->texts[$memoKey] = $found;
        }

        return $found;
    }

    /** Whether an override can answer $key now (so its text is never kept: an override may change). */
    private function overrideApplies(string $key, bool $overrides): bool
    {
        return ($overrides && $this->isOverridable($key)) || isset($this->alwaysOverridden[strstr($key, '.', true) ?: $key]);
    }

    /** The text in exactly one locale: an override (when $overrideApplies), else the files. */
    private function valueIn(string $key, string $locale, bool $overrideApplies): ?string
    {
        if ($overrideApplies) {
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
