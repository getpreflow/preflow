# Preflow I18n

Translations, locale detection, and pluralization for Preflow applications.

## Installation

```bash
composer require preflow/i18n
```

## What it does

- Loads PHP translation files from `lang/{locale}/{group}.php`, and the groups installed packages
  declare (`extra.preflow.translations`), with the site's files laid over them key by key
- Admin overrides (a layer for texts changed in the admin), display labels as plain strings or
  per-language maps, and a `translations:check` command
- Resolves dot-notation keys with `:param` replacement and locale fallback
- ICU-style pluralization with exact, range, and simple two-form rules
- PSR-15 middleware for locale detection (URL prefix → cookie → Accept-Language)
- Twig functions for global and component-scoped translations

## Configuration

`config/i18n.php`:

```php
return [
    'default'      => 'en',
    'available'    => ['en', 'de'],
    'fallback'     => 'en',
    'url_strategy' => 'prefix', // 'prefix' | 'none'
];
```

## Translation files

`lang/en/blog.php`:

```php
return [
    'title'      => 'My Blog',
    'published'  => 'Published on :date',
    'post_count' => '{0} No posts|{1} One post|[2,*] :count posts',
];
```

## API

### Translator

```php
$translator = new Translator(
    langPath: __DIR__ . '/lang',
    locale: 'en',
    fallbackLocale: 'en',
);

$translator->get('blog.title');                          // "My Blog"
$translator->get('blog.published', ['date' => '2026-01-01']); // "Published on 2026-01-01"
$translator->choice('blog.post_count', 5);              // "5 posts"
$translator->setLocale('de');
$translator->getLocale();                               // "de"
$translator->get('blog.title', [], 'fr');               // in another locale, the current one unchanged
```

Parameters are replaced one after the other in the order they are passed. In one text, don't use
a parameter whose name is the start of another (`:to` next to `:total`): `:to` would also replace
the start of `:total`.

## Package translations

A package declares its translation groups in its `composer.json`. Preflow reads them from
Composer's `vendor/composer/installed.json` when the app boots, like `extra.preflow.commands`:

```json
"extra": { "preflow": { "translations": {
    "members":       { "path": "lang", "scope": "public" },
    "members-admin": { "path": "lang", "scope": "admin" },
    "codes": "lang"
} } }
```

- The key is the group: `lang/{locale}/members-admin.php` holds the keys `members-admin.…`.
- `path` is the folder, relative to the package root. It must stay inside the package.
- `scope` is `admin` (the admin interface and mails to staff) or `public` (member pages, mails
  to members, validation on public forms). A plain string is the path with scope `admin`.
- A bad declaration (unknown scope, missing folder, a path outside the package, a group a second
  package declares again) is skipped with an `error_log` line. It never breaks the boot.

The declaration replaces the `$translator->addDefaults('group', $path)` call a provider used to
make. `addDefaultsIfMissing('group', $path, 'admin')` is still there for apps without Composer
metadata (tests); it does nothing when a declaration already registered the group.

A translator is booted on every site that has `preflow/i18n`, with or without a `lang/` folder,
so packages always have their texts. The locale middleware is added only when the site has
`lang/` or `config/i18n.php`, so the frontend of a site without either does not change.

### Which text wins

For each locale in the chain (the asked locale, then the site default, then `en`), the first
of these that has the key:

1. an admin override (admin requests only, `admin` groups only, see below);
2. the site's `lang/{locale}/{group}.php`, key by key;
3. the package's file.

A key found nowhere renders as the key itself; `translations:check` catches it. A site
changes single phrases of a package by putting only those keys into its own file of the same
group.

### Admin overrides

`TranslationOverrides` (`get(string $locale, string $key): ?string`) is the layer above the
site files. `Translator::setOverrides()` sets it, and `useOverrides(true)` turns it on; it is
off by default. Folio turns it on for the length of an admin request and stores the texts its
Translations screen saves. Outside always-overridden groups (below), an override never reaches a public page:

- only keys of an `admin` group take one, plus keys under prefixes registered with
  `Translator::overridable('site.types.')` (Folio registers its model-label prefixes);
- a `public` group never does, unless it is registered with `alwaysOverride()`.

A site group that staff edit but public pages or mails render (Folio fills these from
`translations.editable` in `config/folio.php`) is registered with
`Translator::alwaysOverride('review-mail')`: its overrides apply on every request, with
`useOverrides()` on or off, in the asked locale (`get($key, $params, 'fr')`). A locale's own
file still wins over a fallback locale's override. An `admin` group throws
`InvalidArgumentException`, and a group declared `admin` later (`setScopes()`,
`addDefaultsIfMissing()`) drops out; `alwaysOverridden()` lists the groups, sorted.

`Translator::isOverridable($key)` tells whether a key can take one.
`packageEntries($group, $locale)` and `siteEntries($group, $locale)` list each layer on its
own; `entries()` gives both merged, without overrides.

### Labels from other files

Model files write display texts as a plain string or as a map per language:
`"label": "Apps"` or `"label": {"en": "Apps", "de": "Anwendungen"}`.

- `Labels::pick($value, $locale, $default)` picks the locale's entry, else the default's, else
  English, else the first.
- `Labels::resolve($translator, $key, $value)`: a translation key wins (override, site file,
  package file, in the translator's current locale); then `pick()`; then the key along the
  fallback chain; else null.

`DerivedKeys` collects keys that come from such files, with the file's own text, so
`translations:check` can check them: `$derived->add('Folio model labels', fn () => [key => text])`.

## `preflow translations:check`

```bash
php preflow translations:check            # every group
php preflow translations:check --group=folio
```

The release gate for "no raw keys". It lists:

- per group and language, the keys that exist in English but not in that language (site and
  package files together). A package group's own keys are checked in every language any
  package delivers; keys a site adds to a package group (in its `lang/en/{group}.php`) only in
  the languages the site has `lang/` files for, so a site offering English and German isn't
  asked for French texts of its own keys; a site group in the languages it has files for;
- keys used as `t('…')` in package templates and model files and in the site's `app/`,
  `resources/`, `templates/` and `config/models/`, that exist in no language;
- derived keys (model labels) of package groups that have no text in a delivered language,
  neither in a language file nor in the model file's map. A site's own types need none: their
  plain label is a valid fallback.

It exits 1 when anything is missing, else prints `Translations complete: N group(s), en, de, fr.`
The command comes with `preflow/i18n` (`extra.preflow.commands`) and needs `preflow/devtools`.
A site sees it after `composer update` has refreshed `vendor/composer/installed.json`.

### PluralResolver

Supports three formats:

```
{0} No posts|{1} One post|[2,*] :count posts   // exact + range
[2,10] Some|[11,*] Many                         // range only
One post|:count posts                           // simple two-form (1 = first, else second)
```

### LocaleMiddleware

PSR-15 middleware. Detects locale in priority order: URL prefix → cookie → `Accept-Language` header → configured default. `Accept-Language` is read by `AcceptLanguage::best()`; a language with `q=0` means "not this one" and is never picked (since 0.37). Strips the locale prefix from the request path. A `locale` cookie is set only when enabled (`'cookie' => true` in `config/i18n.php`; the default is off for the `prefix` strategy, on for `none`) and only when the locale changed.

Template helpers: `t()`/`tc()` (parameters are HTML-escaped), `current_locale()`, `available_locales()` and `locale_url(path, locale = current)`, which returns `/about` for the default locale and `/de/about` otherwise.

```php
$middleware = new LocaleMiddleware(
    translator: $translator,
    availableLocales: ['en', 'de'],
    defaultLocale: 'en',
    urlStrategy: 'prefix', // request to /de/blog strips /de, sets locale to "de"
);
```

`urlPrefix()` (since 0.46) returns the prefix stripped from the current request's URL: `'/de'` for `/de/blog`, `'/en'` when the default locale was in the URL, and `''` when there was none or the strategy is `none`. It is set only while `process()` runs: cleared on entry and restored to the previous value when `process()` returns or throws, so a long-running worker never carries it into the next request, and code that runs after the response (deferred mail) or in a nested handle never sees a stale prefix. Preflow's core registers the middleware in the container as `LocaleMiddleware::class` and uses `urlPrefix()` to keep component URLs on the page's locale (`/de/--component/…`, see preflow/htmx).

### TranslationExtension (Twig)

Register with your Twig environment:

```php
$twig->addExtension(new TranslationExtension($translator));
```

```twig
{# Simple key #}
{{ t('blog.title') }}

{# With parameters #}
{{ t('blog.published', { date: '2026-01-01' }) }}

{# Pluralization — third arg is the count #}
{{ t('blog.post_count', { count: 5 }, 5) }}

{# Component-scoped — resolves "my-component.label" #}
{{ tc('label', 'MyComponent') }}
```

`tc()` converts the component name from PascalCase to kebab-case and uses it as the translation group.
