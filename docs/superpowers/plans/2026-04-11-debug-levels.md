# Debug Levels & .env Loading — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the boolean `debug` flag with an integer-backed `DebugLevel` enum, and add `.env` file loading so `config/app.php` reads from environment variables.

**Architecture:** New `DebugLevel` enum in core, new `EnvLoader` in core. `Application::create()` loads `.env` before config. `boot()` converts config integer to enum and passes it through existing constructor injection. ErrorBoundary gains a third mode (Verbose) that overrides custom fallbacks.

**Tech Stack:** PHP 8.5, PHPUnit, no new dependencies

---

### Task 1: DebugLevel Enum

**Files:**
- Create: `packages/core/src/DebugLevel.php`
- Test: `packages/core/tests/DebugLevelTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\DebugLevel;

final class DebugLevelTest extends TestCase
{
    public function test_off_has_value_zero(): void
    {
        $this->assertSame(0, DebugLevel::Off->value);
    }

    public function test_on_has_value_one(): void
    {
        $this->assertSame(1, DebugLevel::On->value);
    }

    public function test_verbose_has_value_two(): void
    {
        $this->assertSame(2, DebugLevel::Verbose->value);
    }

    public function test_from_creates_correct_case(): void
    {
        $this->assertSame(DebugLevel::Off, DebugLevel::from(0));
        $this->assertSame(DebugLevel::On, DebugLevel::from(1));
        $this->assertSame(DebugLevel::Verbose, DebugLevel::from(2));
    }

    public function test_from_throws_on_invalid_value(): void
    {
        $this->expectException(\ValueError::class);
        DebugLevel::from(3);
    }

    public function test_is_debug_returns_false_for_off(): void
    {
        $this->assertFalse(DebugLevel::Off->isDebug());
    }

    public function test_is_debug_returns_true_for_on(): void
    {
        $this->assertTrue(DebugLevel::On->isDebug());
    }

    public function test_is_debug_returns_true_for_verbose(): void
    {
        $this->assertTrue(DebugLevel::Verbose->isDebug());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/core/tests/DebugLevelTest.php`
Expected: FAIL — class `Preflow\Core\DebugLevel` not found

- [ ] **Step 3: Write the enum**

Create `packages/core/src/DebugLevel.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core;

enum DebugLevel: int
{
    case Off = 0;
    case On = 1;
    case Verbose = 2;

    public function isDebug(): bool
    {
        return $this !== self::Off;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/core/tests/DebugLevelTest.php`
Expected: 8 tests, 10 assertions, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/DebugLevel.php packages/core/tests/DebugLevelTest.php
git commit -m "feat(core): add DebugLevel enum with Off/On/Verbose levels"
```

---

### Task 2: EnvLoader

**Files:**
- Create: `packages/core/src/EnvLoader.php`
- Test: `packages/core/tests/EnvLoaderTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\EnvLoader;

final class EnvLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_env_test_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Clean up env vars set during tests
        foreach (['TEST_NAME', 'TEST_DEBUG', 'TEST_KEY', 'TEST_QUOTED', 'TEST_SINGLE', 'TEST_EMPTY', 'TEST_EQUALS', 'TEST_INLINE', 'TEST_EXISTING', 'TEST_SPACES'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        @unlink($this->tmpDir . '/.env');
        @rmdir($this->tmpDir);
    }

    public function test_parses_simple_key_value(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_NAME=MyApp\nTEST_DEBUG=1\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('MyApp', getenv('TEST_NAME'));
        $this->assertSame('1', getenv('TEST_DEBUG'));
        $this->assertSame('MyApp', $_ENV['TEST_NAME']);
    }

    public function test_parses_double_quoted_values(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'TEST_QUOTED="Hello World"' . "\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('Hello World', getenv('TEST_QUOTED'));
    }

    public function test_parses_single_quoted_values(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_SINGLE='Hello World'\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('Hello World', getenv('TEST_SINGLE'));
    }

    public function test_skips_comments_and_blank_lines(): void
    {
        $content = "# This is a comment\n\nTEST_KEY=value\n  # indented comment\n";
        file_put_contents($this->tmpDir . '/.env', $content);

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('value', getenv('TEST_KEY'));
    }

    public function test_handles_empty_values(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_EMPTY=\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('', getenv('TEST_EMPTY'));
    }

    public function test_handles_equals_in_value(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_EQUALS=abc=def=ghi\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('abc=def=ghi', getenv('TEST_EQUALS'));
    }

    public function test_strips_inline_comments(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_INLINE=value # this is a comment\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('value', getenv('TEST_INLINE'));
    }

    public function test_does_not_overwrite_existing_env(): void
    {
        putenv('TEST_EXISTING=original');
        file_put_contents($this->tmpDir . '/.env', "TEST_EXISTING=overwritten\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('original', getenv('TEST_EXISTING'));
    }

    public function test_silent_noop_when_file_missing(): void
    {
        // Should not throw
        EnvLoader::load($this->tmpDir . '/.env.nonexistent');

        $this->assertTrue(true); // no exception = pass
    }

    public function test_trims_whitespace_around_key_and_value(): void
    {
        file_put_contents($this->tmpDir . '/.env', "  TEST_SPACES  =  hello  \n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('hello', getenv('TEST_SPACES'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/core/tests/EnvLoaderTest.php`
Expected: FAIL — class `Preflow\Core\EnvLoader` not found

- [ ] **Step 3: Write EnvLoader**

Create `packages/core/src/EnvLoader.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core;

final class EnvLoader
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments and blank lines
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Must contain =
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));

            // Don't overwrite existing environment variables
            if (getenv($key) !== false) {
                continue;
            }

            // Strip quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            } else {
                // Strip inline comments (only for unquoted values)
                $commentPos = strpos($value, ' #');
                if ($commentPos !== false) {
                    $value = trim(substr($value, 0, $commentPos));
                }
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/core/tests/EnvLoaderTest.php`
Expected: 10 tests, 12 assertions, all PASS

- [ ] **Step 5: Commit**

```bash
git add packages/core/src/EnvLoader.php packages/core/tests/EnvLoaderTest.php
git commit -m "feat(core): add EnvLoader for .env file parsing"
```

---

### Task 3: Wire EnvLoader into Application::create()

**Files:**
- Modify: `packages/core/src/Application.php:54-64` (`create()` method)

- [ ] **Step 1: Write the failing test**

Create `packages/core/tests/ApplicationEnvTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

final class ApplicationEnvTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_app_env_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
    }

    protected function tearDown(): void
    {
        foreach (['APP_NAME', 'APP_DEBUG', 'APP_KEY', 'APP_TIMEZONE', 'APP_LOCALE'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        @unlink($this->tmpDir . '/.env');
        @unlink($this->tmpDir . '/config/app.php');
        @rmdir($this->tmpDir . '/config');
        @rmdir($this->tmpDir);
    }

    public function test_create_loads_env_file_before_config(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_NAME=FromEnv\nAPP_DEBUG=2\n");
        file_put_contents($this->tmpDir . '/config/app.php', <<<'PHP'
        <?php
        return [
            'name' => getenv('APP_NAME') ?: 'Default',
            'debug' => (int) (getenv('APP_DEBUG') ?: 0),
        ];
        PHP);

        $app = Application::create($this->tmpDir);

        $this->assertSame('FromEnv', $app->config()->get('app.name'));
        $this->assertSame(2, $app->config()->get('app.debug'));
    }

    public function test_create_works_without_env_file(): void
    {
        file_put_contents($this->tmpDir . '/config/app.php', <<<'PHP'
        <?php
        return [
            'name' => 'Hardcoded',
            'debug' => 0,
        ];
        PHP);

        $app = Application::create($this->tmpDir);

        $this->assertSame('Hardcoded', $app->config()->get('app.name'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/core/tests/ApplicationEnvTest.php`
Expected: FAIL — `test_create_loads_env_file_before_config` fails because `create()` doesn't load `.env` yet, so `getenv('APP_NAME')` returns false and config gets 'Default'

- [ ] **Step 3: Add EnvLoader call to Application::create()**

In `packages/core/src/Application.php`, add the `use` statement and modify `create()`:

Add to imports (after existing `use` statements near the top of the file):
```php
use Preflow\Core\EnvLoader;
```

Replace lines 54-64 of `create()`:
```php
    public static function create(string|array $basePath = '.'): self
    {
        if (is_array($basePath)) {
            // Legacy/testing: pass config directly
            return new self(new Config($basePath), getcwd() ?: '.');
        }

        EnvLoader::load(rtrim($basePath, '/') . '/.env');

        $configPath = rtrim($basePath, '/') . '/config/app.php';
        $appConfig = file_exists($configPath) ? require $configPath : [];

        return new self(new Config(['app' => $appConfig]), $basePath);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/core/tests/ApplicationEnvTest.php`
Expected: 2 tests, 3 assertions, all PASS

- [ ] **Step 5: Run full test suite to check for regressions**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit`
Expected: All 356+ tests pass

- [ ] **Step 6: Commit**

```bash
git add packages/core/src/Application.php packages/core/tests/ApplicationEnvTest.php
git commit -m "feat(core): load .env file in Application::create() before config"
```

---

### Task 4: Update ErrorBoundary to use DebugLevel

**Files:**
- Modify: `packages/components/src/ErrorBoundary.php`
- Modify: `packages/components/tests/ErrorBoundaryTest.php`

- [ ] **Step 1: Update existing tests to use DebugLevel**

In `packages/components/tests/ErrorBoundaryTest.php`, add the import and replace all `debug: true` with `debug: DebugLevel::On` and `debug: false` with `debug: DebugLevel::Off`:

Add import:
```php
use Preflow\Core\DebugLevel;
```

Replace all occurrences of `new ErrorBoundary(debug: true)` with `new ErrorBoundary(debug: DebugLevel::On)` (6 occurrences: lines 27, 38, 49, 60, 73, 84).

Replace all occurrences of `new ErrorBoundary(debug: false)` with `new ErrorBoundary(debug: DebugLevel::Off)` (3 occurrences: lines 95, 108, 120).

- [ ] **Step 2: Add new Verbose tests**

Append these tests to the `ErrorBoundaryTest` class, before the closing `}`:

```php
    public function test_verbose_overrides_custom_fallback_with_dev_panel(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('Hidden by fallback normally');

        $html = $boundary->render($exception, $component);

        // Should show dev panel, not the custom fallback
        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Hidden by fallback normally', $html);
        $this->assertStringNotContainsString('custom-fallback', $html);
    }

    public function test_verbose_shows_fallback_suppressed_note(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new FallbackComponent();
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('custom fallback', $html);
        $this->assertStringContainsString('suppressed', $html);
    }

    public function test_verbose_shows_dev_panel_for_no_fallback_component(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new NoFallbackComponent();
        $exception = new \RuntimeException('Verbose error');

        $html = $boundary->render($exception, $component);

        $this->assertStringContainsString('RuntimeException', $html);
        $this->assertStringContainsString('Verbose error', $html);
    }

    public function test_verbose_shows_full_dev_panel_content(): void
    {
        $boundary = new ErrorBoundary(debug: DebugLevel::Verbose);
        $component = new NoFallbackComponent();
        $component->setProps(['id' => '99']);
        $exception = new \RuntimeException('err');

        $html = $boundary->render($exception, $component, 'resolveState');

        $this->assertStringContainsString('NoFallbackComponent', $html);
        $this->assertStringContainsString('resolveState', $html);
        $this->assertStringContainsString('99', $html);
        $this->assertStringContainsString('Stack Trace', $html);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/components/tests/ErrorBoundaryTest.php`
Expected: FAIL — ErrorBoundary constructor still expects `bool`, not `DebugLevel`

- [ ] **Step 4: Update ErrorBoundary implementation**

Replace the full content of `packages/components/src/ErrorBoundary.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Components;

use Preflow\Core\DebugLevel;

final class ErrorBoundary
{
    public function __construct(
        private readonly DebugLevel $debug = DebugLevel::Off,
    ) {}

    public function render(
        \Throwable $exception,
        Component $component,
        string $phase = 'unknown',
    ): string {
        // Verbose mode: always show dev panel, even if component has a custom fallback
        if ($this->debug === DebugLevel::Verbose) {
            $hasFallback = $component->fallback($exception) !== null;
            return $this->renderDev($exception, $component, $phase, $hasFallback);
        }

        // If the component provides a custom fallback, always use it —
        // even in debug mode. A custom fallback is intentional.
        $fallback = $component->fallback($exception);
        if ($fallback !== null) {
            return $fallback;
        }

        if ($this->debug->isDebug()) {
            return $this->renderDev($exception, $component, $phase);
        }

        return $this->renderProd();
    }

    private function renderDev(
        \Throwable $exception,
        Component $component,
        string $phase,
        bool $fallbackSuppressed = false,
    ): string {
        $class = $this->esc($exception::class);
        $message = $this->esc($exception->getMessage());
        $componentClass = $this->esc($component::class);
        $componentId = $this->esc($component->getComponentId());
        $file = $this->esc($exception->getFile());
        $line = $exception->getLine();
        $trace = $this->esc($exception->getTraceAsString());
        $phase = $this->esc($phase);
        $props = $this->esc(json_encode($component->getProps(), JSON_PRETTY_PRINT));

        $suppressedNote = $fallbackSuppressed
            ? '<div style="background:#f39c12;color:#1a1a2e;padding:0.5rem 1rem;margin:-1rem -1rem 1rem;font-size:0.8rem;">This component defines a custom fallback (suppressed by debug level 2)</div>'
            : '';

        return <<<HTML
        <div style="border:2px solid #e74c3c;background:#1a1a2e;color:#eee;padding:1rem;border-radius:0.5rem;margin:0.5rem 0;font-family:system-ui,sans-serif;font-size:0.875rem;">
            <div style="background:#e74c3c;margin:-1rem -1rem 1rem;padding:0.75rem 1rem;border-radius:0.375rem 0.375rem 0 0;">
                <strong>{$class}</strong>: {$message}
            </div>
            {$suppressedNote}
            <dl style="display:grid;grid-template-columns:auto 1fr;gap:0.25rem 1rem;margin:0;">
                <dt style="color:#888;">Component</dt><dd style="margin:0;font-family:monospace;">{$componentClass}</dd>
                <dt style="color:#888;">ID</dt><dd style="margin:0;font-family:monospace;">{$componentId}</dd>
                <dt style="color:#888;">Phase</dt><dd style="margin:0;font-family:monospace;">{$phase}</dd>
                <dt style="color:#888;">Props</dt><dd style="margin:0;font-family:monospace;white-space:pre-wrap;">{$props}</dd>
                <dt style="color:#888;">File</dt><dd style="margin:0;font-family:monospace;">{$file}:{$line}</dd>
            </dl>
            <details style="margin-top:0.75rem;">
                <summary style="cursor:pointer;color:#888;">Stack Trace</summary>
                <pre style="margin:0.5rem 0 0;font-size:0.75rem;overflow-x:auto;white-space:pre-wrap;">{$trace}</pre>
            </details>
        </div>
        HTML;
    }

    private function renderProd(): string
    {
        return '<div style="display:none;" data-component-error="true"></div>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit packages/components/tests/ErrorBoundaryTest.php`
Expected: 13 tests (9 existing + 4 new), all PASS

- [ ] **Step 6: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit`
Expected: All tests pass. Note: `Application::boot()` still passes `bool` to `ErrorBoundary` at this point, but that code path is not covered by unit tests — it's wiring code. Task 5 fixes the type mismatch.

- [ ] **Step 7: Commit**

```bash
git add packages/components/src/ErrorBoundary.php packages/components/tests/ErrorBoundaryTest.php
git commit -m "feat(components): ErrorBoundary uses DebugLevel enum with Verbose override"
```

---

### Task 5: Update Application::boot() to use DebugLevel

**Files:**
- Modify: `packages/core/src/Application.php:117-149` (`boot()` method), `:229-251` (`bootViewLayer()`), `:253-323` (`bootComponentLayer()`)

- [ ] **Step 1: Update boot(), bootViewLayer(), and bootComponentLayer()**

In `packages/core/src/Application.php`:

Add import (near top with other use statements):
```php
use Preflow\Core\DebugLevel;
```

Replace line 119:
```php
        $debug = (bool) $this->config->get('app.debug', false);
```
with:
```php
        $debug = DebugLevel::from((int) $this->config->get('app.debug', 0));
```

Replace line 133:
```php
        $renderer = $debug ? new DevErrorRenderer() : new ProdErrorRenderer();
```
with:
```php
        $renderer = $debug->isDebug() ? new DevErrorRenderer() : new ProdErrorRenderer();
```

Replace the `bootViewLayer` signature at line 229:
```php
    private function bootViewLayer(bool $debug): void
```
with:
```php
    private function bootViewLayer(DebugLevel $debug): void
```

Replace line 236:
```php
        $assets = new \Preflow\View\AssetCollector($nonce, isProd: !$debug);
```
with:
```php
        $assets = new \Preflow\View\AssetCollector($nonce, isProd: !$debug->isDebug());
```

Replace line 246:
```php
            debug: $debug,
```
with:
```php
            debug: $debug->isDebug(),
```

Replace the `bootComponentLayer` signature at line 253:
```php
    private function bootComponentLayer(bool $debug, string $secretKey): void
```
with:
```php
    private function bootComponentLayer(DebugLevel $debug, string $secretKey): void
```

Line 265 (`ErrorBoundary(debug: $debug)`) needs no change — it already passes `$debug` which is now `DebugLevel`.

- [ ] **Step 2: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit`
Expected: All tests pass (356+ existing + new tests from earlier tasks)

- [ ] **Step 3: Commit**

```bash
git add packages/core/src/Application.php
git commit -m "feat(core): Application::boot() uses DebugLevel enum throughout"
```

---

### Task 6: Update skeleton config and .env

**Files:**
- Modify: `packages/skeleton/config/app.php`
- Modify: `packages/skeleton/.env.example`

- [ ] **Step 1: Update config/app.php to read from environment**

Replace the full content of `packages/skeleton/config/app.php`:

```php
<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    // 0 = production, 1 = development, 2 = verbose (forces dev panels for all components)
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
];
```

- [ ] **Step 2: Update .env.example**

Replace the full content of `packages/skeleton/.env.example`:

```
APP_NAME="Preflow App"
APP_DEBUG=1
APP_KEY=change-this-to-a-random-32-char-string!
APP_TIMEZONE=UTC
APP_LOCALE=en

DB_DRIVER=sqlite
DB_PATH=storage/data/app.sqlite
```

- [ ] **Step 3: Run full test suite**

Run: `cd /Users/smyr/Sites/gbits/flopp && vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add packages/skeleton/config/app.php packages/skeleton/.env.example
git commit -m "feat(skeleton): config reads from .env, debug uses integer levels"
```

---

### Task 7: Update test project and verify in browser

**Files:**
- Modify: `/Users/smyr/Sites/gbits/preflow/config/app.php` (test project copy)
- Create: `/Users/smyr/Sites/gbits/preflow/.env` (from .env.example)

- [ ] **Step 1: Copy updated skeleton config to test project**

```bash
cp /Users/smyr/Sites/gbits/flopp/packages/skeleton/config/app.php /Users/smyr/Sites/gbits/preflow/config/app.php
cp /Users/smyr/Sites/gbits/flopp/packages/skeleton/.env.example /Users/smyr/Sites/gbits/preflow/.env.example
```

- [ ] **Step 2: Create .env in test project**

Create `/Users/smyr/Sites/gbits/preflow/.env`:

```
APP_NAME="Preflow App"
APP_DEBUG=1
APP_KEY=change-this-to-a-random-32-char-string!
APP_TIMEZONE=UTC
APP_LOCALE=en

DB_DRIVER=sqlite
DB_PATH=storage/data/app.sqlite
```

- [ ] **Step 3: Verify level 1 — custom fallback shown for ErrorDemo, dev panel for ErrorDemoRaw**

Start the dev server and check the home page in a browser:
```bash
cd /Users/smyr/Sites/gbits/preflow && php -S localhost:8080 -t public
```

Visit `http://localhost:8080`. Confirm:
- ErrorDemo shows its custom fallback (yellow warning box)
- ErrorDemoRaw shows the red dev error panel

- [ ] **Step 4: Verify level 2 — dev panel forced for ErrorDemo too**

Change `.env` to `APP_DEBUG=2`, restart server, refresh page. Confirm:
- ErrorDemo now shows the red dev error panel with "custom fallback suppressed" note
- ErrorDemoRaw shows the red dev error panel (same as level 1)

- [ ] **Step 5: Verify level 0 — production mode**

Change `.env` to `APP_DEBUG=0`, restart server, refresh page. Confirm:
- ErrorDemo shows its custom fallback (fallback always shown regardless of level)
- ErrorDemoRaw renders nothing visible (hidden div)
- No error details leaked anywhere

- [ ] **Step 6: Set .env back to level 1 for normal development**

Change `.env` back to `APP_DEBUG=1`.
