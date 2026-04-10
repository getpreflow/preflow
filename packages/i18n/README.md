# Preflow I18n

Translations, locale detection, and pluralization for Preflow applications.

## Installation

```bash
composer require preflow/i18n
```

## What it does

- Loads PHP translation files from `lang/{locale}/{group}.php`
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
```

### PluralResolver

Supports three formats:

```
{0} No posts|{1} One post|[2,*] :count posts   // exact + range
[2,10] Some|[11,*] Many                         // range only
One post|:count posts                           // simple two-form (1 = first, else second)
```

### LocaleMiddleware

PSR-15 middleware. Detects locale in priority order: URL prefix → cookie → `Accept-Language` header → configured default. Strips the locale prefix from the request path and sets a `locale` cookie on the response.

```php
$middleware = new LocaleMiddleware(
    translator: $translator,
    availableLocales: ['en', 'de'],
    defaultLocale: 'en',
    urlStrategy: 'prefix', // request to /de/blog strips /de, sets locale to "de"
);
```

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
