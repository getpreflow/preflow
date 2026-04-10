# Phase 7: preflow/i18n — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the i18n package (`preflow/i18n`) — translation file loading, parameter replacement, pluralization, locale detection middleware, and Twig `t()` / `tc()` functions for template translations.

**Architecture:** The `Translator` loads PHP translation files from a locale directory structure (`lang/{locale}/{group}.php`), resolves keys with dot notation (`group.key`), replaces `:param` placeholders, and handles ICU-style pluralization. `LocaleMiddleware` detects the locale from URL prefix, cookie, or Accept-Language header. A Twig extension provides `t()` for global translations and `tc()` for component-scoped translations.

**Tech Stack:** PHP 8.5+, PHPUnit 11+, preflow/core (Config, MiddlewarePipeline)

**Scope:** This phase covers the core translation system. Translatable model fields (auto-join translation tables) are deferred — they require deeper data layer integration and will come as an enhancement to `preflow/data`.

---

## File Structure

```
packages/i18n/
├── src/
│   ├── Translator.php                  — Loads translations, resolves keys, pluralizes
│   ├── PluralResolver.php              — ICU-style pluralization rules
│   ├── LocaleMiddleware.php            — PSR-15 middleware for locale detection
│   ├── Twig/
│   │   └── TranslationExtension.php    — t() and tc() Twig functions
├── tests/
│   ├── TranslatorTest.php
│   ├── PluralResolverTest.php
│   ├── LocaleMiddlewareTest.php
│   ├── Twig/
│   │   └── TranslationExtensionTest.php
└── composer.json
```

---

### Task 1: Package Scaffolding

**Files:**
- Create: `packages/i18n/composer.json`
- Modify: `composer.json` (root)
- Modify: `phpunit.xml`

- [ ] **Step 1: Create packages/i18n/composer.json**

```json
{
    "name": "preflow/i18n",
    "description": "Preflow i18n — translations, locale detection, pluralization",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "^0.1 || @dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0",
        "nyholm/psr7": "^1.8",
        "twig/twig": "^3.0"
    },
    "autoload": {
        "psr-4": {
            "Preflow\\I18n\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Preflow\\I18n\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Update root composer.json**

Add to `repositories`:
```json
{ "type": "path", "url": "packages/i18n", "options": { "symlink": true } }
```
Add `"preflow/i18n": "@dev"` to `require-dev`.

- [ ] **Step 3: Update phpunit.xml**

Add testsuite `I18n` pointing to `packages/i18n/tests`. Add `packages/i18n/src` to source include.

- [ ] **Step 4: Create directories and install**

```bash
mkdir -p packages/i18n/src/Twig packages/i18n/tests/Twig
composer update
```

- [ ] **Step 5: Commit**

```bash
git add packages/i18n/composer.json composer.json phpunit.xml
git commit -m "feat: scaffold preflow/i18n package"
```

---

### Task 2: PluralResolver

**Files:**
- Create: `packages/i18n/src/PluralResolver.php`
- Create: `packages/i18n/tests/PluralResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/i18n/tests/PluralResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\PluralResolver;

final class PluralResolverTest extends TestCase
{
    private PluralResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PluralResolver();
    }

    public function test_exact_match_zero(): void
    {
        $result = $this->resolver->resolve(
            '{0} No posts|{1} One post|[2,*] :count posts',
            0
        );

        $this->assertSame('No posts', $result);
    }

    public function test_exact_match_one(): void
    {
        $result = $this->resolver->resolve(
            '{0} No posts|{1} One post|[2,*] :count posts',
            1
        );

        $this->assertSame('One post', $result);
    }

    public function test_range_match(): void
    {
        $result = $this->resolver->resolve(
            '{0} No posts|{1} One post|[2,*] :count posts',
            5
        );

        $this->assertSame(':count posts', $result);
    }

    public function test_range_with_upper_bound(): void
    {
        $result = $this->resolver->resolve(
            '[0,1] Few|[2,10] Some|[11,*] Many',
            5
        );

        $this->assertSame('Some', $result);
    }

    public function test_simple_pipe_two_forms(): void
    {
        $result = $this->resolver->resolve('One item|:count items', 1);
        $this->assertSame('One item', $result);

        $result2 = $this->resolver->resolve('One item|:count items', 5);
        $this->assertSame(':count items', $result2);
    }

    public function test_no_plural_returns_string(): void
    {
        $result = $this->resolver->resolve('Hello World', 1);

        $this->assertSame('Hello World', $result);
    }

    public function test_range_boundary_inclusive(): void
    {
        $result = $this->resolver->resolve('[2,10] In range|[11,*] Out', 10);
        $this->assertSame('In range', $result);

        $result2 = $this->resolver->resolve('[2,10] In range|[11,*] Out', 11);
        $this->assertSame('Out', $result2);
    }

    public function test_zero_with_simple_forms(): void
    {
        $result = $this->resolver->resolve('One|Many', 0);

        // 0 uses the second form (plural)
        $this->assertSame('Many', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/i18n/tests/PluralResolverTest.php
```

- [ ] **Step 3: Implement PluralResolver**

Create `packages/i18n/src/PluralResolver.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n;

final class PluralResolver
{
    /**
     * Resolve a pluralized string for the given count.
     *
     * Supports:
     * - Exact match: {0} No items|{1} One item|[2,*] Many items
     * - Range match: [0,5] Few|[6,*] Many
     * - Simple two-form: One item|:count items (1 = first, else second)
     */
    public function resolve(string $message, int $count): string
    {
        // Not a pluralized string
        if (!str_contains($message, '|')) {
            return $message;
        }

        $segments = explode('|', $message);

        // Try exact match {N} first
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (preg_match('/^\{(\d+)\}\s*(.*)$/', $segment, $matches)) {
                if ((int) $matches[1] === $count) {
                    return $matches[2];
                }
                continue;
            }
        }

        // Try range match [min,max]
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (preg_match('/^\[(\d+),(\d+|\*)\]\s*(.*)$/', $segment, $matches)) {
                $min = (int) $matches[1];
                $max = $matches[2] === '*' ? PHP_INT_MAX : (int) $matches[2];

                if ($count >= $min && $count <= $max) {
                    return $matches[3];
                }
                continue;
            }
        }

        // Simple two-form: singular|plural
        // Strip any {N} or [N,M] prefixes for clean segments
        $clean = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            // Skip segments with explicit markers (already tried above)
            if (preg_match('/^\{|\[/', $segment)) {
                continue;
            }
            $clean[] = $segment;
        }

        if ($clean !== []) {
            return $count === 1 ? $clean[0] : ($clean[1] ?? $clean[0]);
        }

        // Fallback: return first segment stripped of markers
        return preg_replace('/^\{[^}]+\}\s*|\[[^\]]+\]\s*/', '', trim($segments[0]));
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/i18n/tests/PluralResolverTest.php
```

Expected: All 8 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/i18n/src/PluralResolver.php packages/i18n/tests/PluralResolverTest.php
git commit -m "feat(i18n): add PluralResolver with exact, range, and simple forms"
```

---

### Task 3: Translator

**Files:**
- Create: `packages/i18n/src/Translator.php`
- Create: `packages/i18n/tests/TranslatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/i18n/tests/TranslatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;

final class TranslatorTest extends TestCase
{
    private string $langDir;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow_i18n_test_' . uniqid();

        // Create en/app.php
        $this->createLangFile('en', 'app', [
            'name' => 'Preflow',
            'welcome' => 'Welcome, :name!',
            'nested' => [
                'deep' => 'Nested value',
            ],
        ]);

        // Create en/blog.php
        $this->createLangFile('en', 'blog', [
            'title' => 'Blog',
            'post_count' => '{0} No posts|{1} One post|[2,*] :count posts',
            'published_at' => 'Published :date',
        ]);

        // Create de/app.php
        $this->createLangFile('de', 'app', [
            'name' => 'Preflow',
            'welcome' => 'Willkommen, :name!',
        ]);

        // Create de/blog.php
        $this->createLangFile('de', 'blog', [
            'title' => 'Blog',
            'post_count' => '{0} Keine Beiträge|{1} Ein Beitrag|[2,*] :count Beiträge',
        ]);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->langDir);
    }

    private function createLangFile(string $locale, string $group, array $translations): void
    {
        $dir = $this->langDir . '/' . $locale;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = '<?php return ' . var_export($translations, true) . ';';
        file_put_contents($dir . '/' . $group . '.php', $content);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_simple_translation(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('Blog', $t->get('blog.title'));
    }

    public function test_with_parameter_replacement(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $result = $t->get('app.welcome', ['name' => 'Steffen']);

        $this->assertSame('Welcome, Steffen!', $result);
    }

    public function test_nested_key(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('Nested value', $t->get('app.nested.deep'));
    }

    public function test_pluralization(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('No posts', $t->choice('blog.post_count', 0));
        $this->assertSame('One post', $t->choice('blog.post_count', 1));
        $this->assertSame('5 posts', $t->choice('blog.post_count', 5, ['count' => 5]));
    }

    public function test_locale_switching(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('Blog', $t->get('blog.title'));

        $t->setLocale('de');

        $this->assertSame('Blog', $t->get('blog.title'));
    }

    public function test_german_translations(): void
    {
        $t = new Translator($this->langDir, 'de', 'en');

        $result = $t->get('app.welcome', ['name' => 'Steffen']);

        $this->assertSame('Willkommen, Steffen!', $result);
    }

    public function test_german_pluralization(): void
    {
        $t = new Translator($this->langDir, 'de', 'en');

        $this->assertSame('Keine Beiträge', $t->choice('blog.post_count', 0));
        $this->assertSame('Ein Beitrag', $t->choice('blog.post_count', 1));
        $this->assertSame('3 Beiträge', $t->choice('blog.post_count', 3, ['count' => 3]));
    }

    public function test_fallback_to_default_locale(): void
    {
        $t = new Translator($this->langDir, 'de', 'en');

        // 'published_at' only exists in en, not in de
        $result = $t->get('blog.published_at', ['date' => '2026-01-01']);

        $this->assertSame('Published 2026-01-01', $result);
    }

    public function test_missing_key_returns_key(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('blog.nonexistent', $t->get('blog.nonexistent'));
    }

    public function test_missing_group_returns_key(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('missing.key', $t->get('missing.key'));
    }

    public function test_get_locale(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        $this->assertSame('en', $t->getLocale());

        $t->setLocale('de');
        $this->assertSame('de', $t->getLocale());
    }

    public function test_caches_loaded_files(): void
    {
        $t = new Translator($this->langDir, 'en', 'en');

        // First call loads from file
        $t->get('blog.title');

        // Delete the file
        unlink($this->langDir . '/en/blog.php');

        // Should still return cached value
        $this->assertSame('Blog', $t->get('blog.title'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/i18n/tests/TranslatorTest.php
```

- [ ] **Step 3: Implement Translator**

Create `packages/i18n/src/Translator.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n;

final class Translator
{
    private string $locale;

    /** @var array<string, array<string, mixed>> Cache: "locale.group" => translations */
    private array $loaded = [];

    public function __construct(
        private readonly string $langPath,
        string $locale,
        private readonly string $fallbackLocale,
    ) {
        $this->locale = $locale;
    }

    /**
     * Translate a key with optional parameter replacement.
     *
     * @param array<string, string|int> $params Parameters to replace (:name → value)
     */
    public function get(string $key, array $params = []): string
    {
        $value = $this->resolve($key, $this->locale);

        // Fallback to default locale
        if ($value === null && $this->locale !== $this->fallbackLocale) {
            $value = $this->resolve($key, $this->fallbackLocale);
        }

        // Key not found — return the key itself
        if ($value === null) {
            return $key;
        }

        if (!is_string($value)) {
            return $key;
        }

        return $this->replaceParams($value, $params);
    }

    /**
     * Translate with pluralization.
     *
     * @param array<string, string|int> $params
     */
    public function choice(string $key, int $count, array $params = []): string
    {
        $value = $this->resolve($key, $this->locale);

        if ($value === null && $this->locale !== $this->fallbackLocale) {
            $value = $this->resolve($key, $this->fallbackLocale);
        }

        if ($value === null || !is_string($value)) {
            return $key;
        }

        $resolver = new PluralResolver();
        $resolved = $resolver->resolve($value, $count);

        $params['count'] = (string) $count;

        return $this->replaceParams($resolved, $params);
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
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

        $path = $this->langPath . '/' . $locale . '/' . $group . '.php';

        if (!file_exists($path)) {
            $this->loaded[$cacheKey] = [];
            return [];
        }

        $translations = require $path;

        if (!is_array($translations)) {
            $this->loaded[$cacheKey] = [];
            return [];
        }

        $this->loaded[$cacheKey] = $translations;

        return $translations;
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
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/i18n/tests/TranslatorTest.php
```

Expected: All 12 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/i18n/src/Translator.php packages/i18n/tests/TranslatorTest.php
git commit -m "feat(i18n): add Translator with param replacement, fallback, and caching"
```

---

### Task 4: LocaleMiddleware

**Files:**
- Create: `packages/i18n/src/LocaleMiddleware.php`
- Create: `packages/i18n/tests/LocaleMiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/i18n/tests/LocaleMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Preflow\I18n\LocaleMiddleware;
use Preflow\I18n\Translator;

final class LocaleMiddlewareTest extends TestCase
{
    private string $langDir;
    private Translator $translator;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow_locale_mw_' . uniqid();
        mkdir($this->langDir . '/en', 0755, true);
        mkdir($this->langDir . '/de', 0755, true);
        file_put_contents($this->langDir . '/en/app.php', '<?php return [];');
        file_put_contents($this->langDir . '/de/app.php', '<?php return [];');

        $this->translator = new Translator($this->langDir, 'en', 'en');
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->langDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createRequest(string $uri, array $headers = [], array $cookies = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        foreach ($cookies as $name => $value) {
            $request = $request->withCookieParams([$name => $value]);
        }
        return $request;
    }

    private function createHandler(?ServerRequestInterface &$captured = null): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private ?ServerRequestInterface &$captured) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return new Response(200);
            }
        };
    }

    public function test_detects_locale_from_url_prefix(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de/blog'), $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
    }

    public function test_strips_locale_prefix_from_uri(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de/blog/post'), $this->createHandler($captured));

        $this->assertSame('/blog/post', $captured->getUri()->getPath());
    }

    public function test_no_prefix_uses_default(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/blog'), $this->createHandler($captured));

        $this->assertSame('en', $this->translator->getLocale());
        $this->assertSame('/blog', $captured->getUri()->getPath());
    }

    public function test_detects_locale_from_cookie(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'none');

        $request = $this->createRequest('/blog', cookies: ['locale' => 'de']);
        $captured = null;
        $mw->process($request, $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
    }

    public function test_detects_locale_from_accept_language(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'none');

        $request = $this->createRequest('/blog', headers: ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8']);
        $captured = null;
        $mw->process($request, $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
    }

    public function test_invalid_locale_uses_default(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/fr/blog'), $this->createHandler($captured));

        $this->assertSame('en', $this->translator->getLocale());
        // Invalid prefix not stripped
        $this->assertSame('/fr/blog', $captured->getUri()->getPath());
    }

    public function test_sets_locale_cookie_in_response(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $response = $mw->process($this->createRequest('/de/blog'), $this->createHandler($captured));

        $this->assertTrue($response->hasHeader('Set-Cookie'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('locale=de', $cookie);
    }

    public function test_root_url_with_prefix(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de'), $this->createHandler($captured));

        $this->assertSame('de', $this->translator->getLocale());
        $this->assertSame('/', $captured->getUri()->getPath());
    }

    public function test_locale_attribute_set_on_request(): void
    {
        $mw = new LocaleMiddleware($this->translator, ['en', 'de'], 'en', 'prefix');

        $captured = null;
        $mw->process($this->createRequest('/de/page'), $this->createHandler($captured));

        $this->assertSame('de', $captured->getAttribute('locale'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/i18n/tests/LocaleMiddlewareTest.php
```

- [ ] **Step 3: Implement LocaleMiddleware**

Create `packages/i18n/src/LocaleMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $availableLocales
     * @param string   $urlStrategy 'prefix' | 'none'
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $availableLocales,
        private readonly string $defaultLocale,
        private readonly string $urlStrategy = 'prefix',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->defaultLocale;
        $strippedRequest = $request;

        // 1. URL prefix detection (highest priority)
        if ($this->urlStrategy === 'prefix') {
            $result = $this->detectFromUrlPrefix($request);
            if ($result !== null) {
                $locale = $result['locale'];
                $strippedRequest = $result['request'];
            }
        }

        // 2. Cookie (if URL didn't match)
        if ($locale === $this->defaultLocale && $this->urlStrategy !== 'prefix') {
            $cookieLocale = $this->detectFromCookie($request);
            if ($cookieLocale !== null) {
                $locale = $cookieLocale;
            }
        }

        // 3. Accept-Language header (if nothing else matched)
        if ($locale === $this->defaultLocale && $this->urlStrategy !== 'prefix') {
            $headerLocale = $this->detectFromAcceptLanguage($request);
            if ($headerLocale !== null) {
                $locale = $headerLocale;
            }
        }

        // Set the locale
        $this->translator->setLocale($locale);

        // Add locale attribute to request
        $strippedRequest = $strippedRequest->withAttribute('locale', $locale);

        // Handle the request
        $response = $handler->handle($strippedRequest);

        // Set locale cookie
        $response = $response->withHeader(
            'Set-Cookie',
            "locale={$locale}; Path=/; SameSite=Lax; HttpOnly"
        );

        return $response;
    }

    /**
     * @return array{locale: string, request: ServerRequestInterface}|null
     */
    private function detectFromUrlPrefix(ServerRequestInterface $request): ?array
    {
        $path = $request->getUri()->getPath();

        // Match /xx or /xx/...
        if (preg_match('#^/([a-z]{2})(?:/|$)#', $path, $matches)) {
            $candidate = $matches[1];

            if (in_array($candidate, $this->availableLocales, true)) {
                // Strip the locale prefix
                $newPath = substr($path, 3) ?: '/';
                $newUri = $request->getUri()->withPath($newPath);

                return [
                    'locale' => $candidate,
                    'request' => $request->withUri($newUri),
                ];
            }
        }

        return null;
    }

    private function detectFromCookie(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $candidate = $cookies['locale'] ?? null;

        if ($candidate !== null && in_array($candidate, $this->availableLocales, true)) {
            return $candidate;
        }

        return null;
    }

    private function detectFromAcceptLanguage(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Accept-Language');

        if ($header === '') {
            return null;
        }

        // Parse Accept-Language: de-DE,de;q=0.9,en;q=0.8
        $parts = explode(',', $header);
        $languages = [];

        foreach ($parts as $part) {
            $part = trim($part);
            $segments = explode(';', $part);
            $lang = strtolower(trim($segments[0]));
            $q = 1.0;

            if (isset($segments[1])) {
                if (preg_match('/q=([0-9.]+)/', $segments[1], $m)) {
                    $q = (float) $m[1];
                }
            }

            // Extract just the language code (e.g., de-DE → de)
            $shortLang = explode('-', $lang)[0];
            $languages[$shortLang] = max($languages[$shortLang] ?? 0, $q);
        }

        // Sort by quality
        arsort($languages);

        // Find first available match
        foreach (array_keys($languages) as $lang) {
            if (in_array($lang, $this->availableLocales, true)) {
                return $lang;
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/i18n/tests/LocaleMiddlewareTest.php
```

Expected: All 9 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/i18n/src/LocaleMiddleware.php packages/i18n/tests/LocaleMiddlewareTest.php
git commit -m "feat(i18n): add LocaleMiddleware with URL prefix, cookie, Accept-Language detection"
```

---

### Task 5: TranslationExtension — Twig `t()` and `tc()`

**Files:**
- Create: `packages/i18n/src/Twig/TranslationExtension.php`
- Create: `packages/i18n/tests/Twig/TranslationExtensionTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/i18n/tests/Twig/TranslationExtensionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\I18n\Translator;
use Preflow\I18n\Twig\TranslationExtension;

final class TranslationExtensionTest extends TestCase
{
    private string $langDir;
    private Translator $translator;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow_twig_i18n_' . uniqid();
        mkdir($this->langDir . '/en', 0755, true);
        mkdir($this->langDir . '/de', 0755, true);

        file_put_contents($this->langDir . '/en/app.php', '<?php return [
            "greeting" => "Hello, :name!",
        ];');

        file_put_contents($this->langDir . '/en/blog.php', '<?php return [
            "title" => "Blog",
            "post_count" => "{0} No posts|{1} One post|[2,*] :count posts",
        ];');

        file_put_contents($this->langDir . '/en/my-component.php', '<?php return [
            "label" => "Component Label",
        ];');

        file_put_contents($this->langDir . '/de/blog.php', '<?php return [
            "title" => "Blog",
        ];');

        $this->translator = new Translator($this->langDir, 'en', 'en');

        $this->twig = new Environment(new ArrayLoader([]), ['autoescape' => false]);
        $this->twig->addExtension(new TranslationExtension($this->translator));
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->langDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function render(string $template, array $context = []): string
    {
        return $this->twig->createTemplate($template)->render($context);
    }

    public function test_t_function_simple(): void
    {
        $result = $this->render("{{ t('blog.title') }}");

        $this->assertSame('Blog', $result);
    }

    public function test_t_function_with_params(): void
    {
        $result = $this->render("{{ t('app.greeting', { name: 'World' }) }}");

        $this->assertSame('Hello, World!', $result);
    }

    public function test_t_function_with_count(): void
    {
        $result = $this->render("{{ t('blog.post_count', { count: 5 }, 5) }}");

        $this->assertSame('5 posts', $result);
    }

    public function test_t_function_count_zero(): void
    {
        $result = $this->render("{{ t('blog.post_count', {}, 0) }}");

        $this->assertSame('No posts', $result);
    }

    public function test_tc_function_with_component_prefix(): void
    {
        $result = $this->render(
            "{{ tc('label', 'MyComponent') }}"
        );

        $this->assertSame('Component Label', $result);
    }

    public function test_tc_falls_back_to_unprefixed(): void
    {
        $result = $this->render("{{ tc('title', 'Unknown') }}");

        // 'unknown.title' doesn't exist, should return key
        $this->assertSame('unknown.title', $result);
    }

    public function test_t_missing_key_returns_key(): void
    {
        $result = $this->render("{{ t('blog.nonexistent') }}");

        $this->assertSame('blog.nonexistent', $result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit packages/i18n/tests/Twig/TranslationExtensionTest.php
```

- [ ] **Step 3: Implement TranslationExtension**

Create `packages/i18n/src/Twig/TranslationExtension.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\I18n\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Preflow\I18n\Translator;

final class TranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translate(...), ['is_safe' => ['html']]),
            new TwigFunction('tc', $this->translateComponent(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Global translation function.
     *
     * {{ t('blog.title') }}
     * {{ t('blog.published_at', { date: '2026-01-01' }) }}
     * {{ t('blog.post_count', { count: 5 }, 5) }}
     *
     * @param array<string, string|int> $params
     */
    public function translate(string $key, array $params = [], ?int $count = null): string
    {
        if ($count !== null) {
            return $this->translator->choice($key, $count, $params);
        }

        return $this->translator->get($key, $params);
    }

    /**
     * Component-scoped translation function.
     *
     * {{ tc('label', 'MyComponent') }}
     * Resolves: my-component.label (kebab-cased component name as group)
     *
     * @param array<string, string|int> $params
     */
    public function translateComponent(
        string $key,
        string $componentName,
        array $params = [],
        ?int $count = null,
    ): string {
        // Convert PascalCase to kebab-case for the translation group
        $group = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $componentName));
        $fullKey = $group . '.' . $key;

        if ($count !== null) {
            return $this->translator->choice($fullKey, $count, $params);
        }

        return $this->translator->get($fullKey, $params);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
./vendor/bin/phpunit packages/i18n/tests/Twig/TranslationExtensionTest.php
```

Expected: All 7 tests pass.

- [ ] **Step 5: Commit**

```bash
git add packages/i18n/src/Twig/TranslationExtension.php packages/i18n/tests/Twig/TranslationExtensionTest.php
git commit -m "feat(i18n): add TranslationExtension with t() and tc() Twig functions"
```

---

### Task 6: Full Test Suite Verification

**Files:**
- No new files

- [ ] **Step 1: Run full test suite**

```bash
./vendor/bin/phpunit --display-errors --display-warnings
```

Expected: All tests pass across all 7 packages.

- [ ] **Step 2: Verify package loads**

```bash
php -r "
require 'vendor/autoload.php';
echo 'Translator: OK' . PHP_EOL;
echo 'PluralResolver: OK' . PHP_EOL;
echo 'LocaleMiddleware: OK' . PHP_EOL;
echo 'TranslationExtension: OK' . PHP_EOL;
"
```

- [ ] **Step 3: Commit if cleanup needed**

---

## Phase 7 Deliverables

| Component | What It Does |
|---|---|
| `PluralResolver` | ICU-style pluralization: exact match `{N}`, range `[N,M]`, simple two-form |
| `Translator` | Loads PHP translation files, dot-notation keys, `:param` replacement, locale fallback, file caching |
| `LocaleMiddleware` | PSR-15 middleware: URL prefix → cookie → Accept-Language → default. Strips prefix, sets cookie. |
| `TranslationExtension` | Twig `t('key', params, count)` and `tc('key', 'ComponentName')` functions |

**Deferred:** Translatable model fields (auto-join translation tables in data layer).

**Next phases:** `preflow/testing`, `preflow/devtools`, `preflow/skeleton`.
