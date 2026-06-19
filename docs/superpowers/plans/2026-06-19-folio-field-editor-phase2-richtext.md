# Folio Field/Editor System — Phase 2 (Rich Text) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `richtext` field type backed by a vendored Trix editor (served from the package's own asset route), with HTML sanitized on save AND on render via `symfony/html-sanitizer`, and wire per-page editor assets + registry-driven frontend rendering.

**Architecture:** Generalize the package-owned asset route to serve an allowlisted set of files (CSS + JS, incl. vendored Trix) via a single `folio_asset()` Twig seam. A `RichTextFieldType` renders a `<trix-editor>` bound to a hidden input (Trix self-initializes — no custom JS needed), sanitizes submitted HTML on save, and re-sanitizes on frontend render (defense in depth). The admin form unions each field type's declared assets into the layout `<head>`; the frontend renders each field through the registry so rich text emits safe HTML.

**Tech Stack:** PHP 8.5, `preflow/folio`, Twig, `symfony/html-sanitizer` (new dep), vendored Trix 2.1.19 (prebuilt UMD bundle + CSS, committed), PHPUnit 11. No build step; no runtime external requests.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step.** Trix is a **prebuilt, pinned bundle vendored into the repo** (`trix@2.1.19`, `dist/trix.umd.min.js` + `dist/trix.css`); we serve it ourselves — **no runtime external request**.
- **New dependency:** `symfony/html-sanitizer` (pure PHP, allowlist). The ONLY new dependency.
- **No emojis** in code or UI copy.
- Folio admin renders in **action mode** (bypasses `AssetCollector`); editor `<link>`/`<script>` are **plain markup** in `_layout.twig`. No `{% apply %}`.
- Rich-text HTML is **sanitized on save** (`normalizeInput`) AND **re-sanitized on frontend render** (`renderFrontend`) — defense in depth, so even pre-existing/dirty stored values render safely.
- Trix self-initializes: a `<trix-editor input="ID">` custom element binds to `<input id="ID">` when `trix.js` loads. **No Folio admin JS is written this phase** (`admin.js` is deferred to Phase 5 / matrix).
- All Folio templates stay overridable via the `@folio` namespace.
- Admin POSTs carry `_csrf_token` — do not remove it.
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Bare `vendor/bin/phpunit` runs all suites.
- Integration tests run under strict Twig (`APP_DEBUG=1`) — guard undefined-variable access.
- Asset URL seam: templates reference assets ONLY via the `folio_asset(name)` Twig function (replaces the Phase-1 `folio_admin_css_url` global). Do not reintroduce `asset_url()` coupling.

## File Structure

- `packages/folio/src/Http/AssetController.php` — **rewrite**: allowlist-based `serve()` (multiple files, content-type by extension).
- `packages/folio/src/Routing/FolioRoutes.php` — **modify**: asset route becomes `{prefix}/_assets/{file}` → `AssetController@serve`.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: asset map; bind `AssetController(baseDir, map)`; register `folio_asset()` function (remove `folio_admin_css_url` global); register `RichTextFieldType` with a sanitizer.
- `packages/folio/templates/admin/_layout.twig` — **modify**: use `folio_asset('admin.css')`; emit `editor_assets`.
- `packages/folio/templates/admin/login.twig` — **modify**: use `folio_asset('admin.css')`.
- `packages/folio/assets/vendor/trix.umd.min.js`, `trix.css`, `VENDOR.md` — **new** (vendored).
- `packages/folio/src/Field/Types/RichTextFieldType.php` — **new**.
- `packages/folio/src/Http/AdminController.php` — **modify**: union field assets → `editor_assets`.
- `packages/folio/src/Http/FrontendController.php` — **modify**: registry-driven `rendered` map.
- `packages/folio/templates/frontend/page.twig` — **modify**: render via `rendered`.
- `packages/folio/composer.json` — **modify**: add `symfony/html-sanitizer`.
- Tests: `packages/folio/tests/Http/AssetControllerTest.php` (rewrite), `FolioRoutesTest.php` (modify), `tests/Field/RichTextFieldTypeTest.php` (new), `tests/Integration/FolioAppTest.php` (modify).
- `examples/folio-demo/config/models/page.json` — **modify** (demo `body` → richtext).

---

### Task 1: Generalize the asset route + `folio_asset()` seam

**Files:**
- Rewrite: `packages/folio/src/Http/AssetController.php`
- Modify: `packages/folio/src/Routing/FolioRoutes.php`
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Modify: `packages/folio/templates/admin/_layout.twig`, `packages/folio/templates/admin/login.twig`
- Test: `packages/folio/tests/Http/AssetControllerTest.php` (rewrite), `packages/folio/tests/Routing/FolioRoutesTest.php` (modify)

**Interfaces:**
- Produces:
  - `AssetController::__construct(string $baseDir, array $allowlist)` where `$allowlist` maps a flat URL filename → a path relative to `$baseDir`. `serve(ServerRequestInterface $request): ResponseInterface` reads the `file` route attribute, 404s if not allowlisted or missing, else returns the file with content-type by extension (`.css`→`text/css`, `.js`→`text/javascript`) + `Cache-Control: public, max-age=31536000, immutable`.
  - Asset route: `GET {prefix}/_assets/{file}` → handler `Preflow\Folio\Http\AssetController@serve`, appended last in `FolioRoutes::admin()`.
  - Twig function `folio_asset(string $file): string` → `{prefix}/_assets/{file}?v=<12-char xxh3 of the resolved file>`. Replaces the `folio_admin_css_url` global.

- [ ] **Step 1: Rewrite the controller test (failing)**

Replace the entire contents of `packages/folio/tests/Http/AssetControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AssetController;

final class AssetControllerTest extends TestCase
{
    private function controller(): AssetController
    {
        // baseDir = packages/folio/assets ; admin.css exists there.
        return new AssetController(dirname(__DIR__, 2) . '/assets', ['admin.css' => 'admin.css']);
    }

    private function get(AssetController $c, string $file)
    {
        return $c->serve((new Psr17Factory())
            ->createServerRequest('GET', '/folio/_assets/' . $file)
            ->withAttribute('file', $file));
    }

    public function test_serves_allowlisted_css_with_type_and_cache(): void
    {
        $res = $this->get($this->controller(), 'admin.css');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('max-age', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }

    public function test_unknown_file_404(): void
    {
        $res = $this->get($this->controller(), 'secrets.env');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_allowlisted_but_missing_file_404(): void
    {
        $c = new AssetController('/no/such/dir', ['admin.css' => 'admin.css']);
        $res = $this->get($c, 'admin.css');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_js_content_type(): void
    {
        // map a js file that exists (use admin.css contents via a .js alias is not valid;
        // instead assert extension-based type using a temp dir)
        $dir = sys_get_temp_dir() . '/folio_assets_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/x.js', '/* js */');
        $c = new AssetController($dir, ['x.js' => 'x.js']);
        $res = $this->get($c, 'x.js');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/javascript', $res->getHeaderLine('Content-Type'));
        @unlink($dir . '/x.js');
        @rmdir($dir);
    }
}
```

- [ ] **Step 2: Run controller test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AssetControllerTest.php`
Expected: FAIL — `AssetController` still has the old `__construct(string $cssPath)` / `adminCss()` signature.

- [ ] **Step 3: Rewrite the controller**

Replace the entire contents of `packages/folio/src/Http/AssetController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves Folio's package-owned admin assets (CSS + JS, incl. vendored editor
 * bundles) from disk via a strict allowlist. URLs are content-hash versioned
 * (see FolioServiceProvider::folio_asset), so responses cache immutably.
 */
final class AssetController
{
    /** @param array<string, string> $allowlist flat URL filename => path relative to $baseDir */
    public function __construct(
        private readonly string $baseDir,
        private readonly array $allowlist,
    ) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $file = (string) $request->getAttribute('file', '');
        $rel = $this->allowlist[$file] ?? null;
        if ($rel === null) {
            return new Response(404, [], 'Not found');
        }

        $path = $this->baseDir . '/' . $rel;
        if (!is_file($path)) {
            return new Response(404, [], 'Not found');
        }

        return new Response(
            200,
            [
                'Content-Type' => $this->contentType($file) . '; charset=UTF-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            (string) file_get_contents($path),
        );
    }

    private function contentType(string $file): string
    {
        return match (true) {
            str_ends_with($file, '.css') => 'text/css',
            str_ends_with($file, '.js') => 'text/javascript',
            default => 'application/octet-stream',
        };
    }
}
```

- [ ] **Step 4: Update the route**

In `packages/folio/src/Routing/FolioRoutes.php`, replace the asset-route block (the `$assetPattern = $prefix . '/_assets/admin.css';` section) with:

```php
        // Package-owned admin assets (CSS/JS, incl. vendored editor bundles).
        // Appended last; single-segment {file} param, allowlist-guarded in the
        // controller. entries[0] stays the dashboard (prefix-configurability).
        $assetPattern = $prefix . '/_assets/{file}';
        $ac = PatternCompiler::compile($assetPattern);
        $entries[] = new RouteEntry(
            pattern: $assetPattern,
            handler: 'Preflow\\Folio\\Http\\AssetController@serve',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $ac['paramNames'],
            regex: $ac['regex'],
            isCatchAll: $ac['isCatchAll'],
        );
```

- [ ] **Step 5: Update the route test**

In `packages/folio/tests/Routing/FolioRoutesTest.php`, replace the `test_admin_includes_stylesheet_asset_route` method with:

```php
    public function test_admin_includes_asset_route(): void
    {
        $entries = FolioRoutes::admin('/folio');

        $match = null;
        foreach ($entries as $e) {
            if ($e->pattern === '/folio/_assets/{file}') {
                $match = $e;
                break;
            }
        }

        $this->assertNotNull($match, 'asset route should be registered');
        $this->assertSame('GET', $match->method);
        $this->assertSame('Preflow\\Folio\\Http\\AssetController@serve', $match->handler);
        $this->assertContains('file', $match->paramNames);
        // Prefix-configurability: dashboard stays first.
        $this->assertSame('/folio', $entries[0]->pattern);
    }
```

- [ ] **Step 6: Update the provider — asset map, binding, `folio_asset()` function**

In `packages/folio/src/FolioServiceProvider.php`:

Add this private helper method (near `prefix()`):

```php
    /**
     * Flat URL filename => path relative to packages/folio/assets.
     *
     * @return array<string, string>
     */
    private function assetMap(): array
    {
        return [
            'admin.css' => 'admin.css',
        ];
    }
```

Replace the `AssetController` binding in `register()`:

```php
        $container->bind(AssetController::class, fn (Container $c) => new AssetController(
            dirname(__DIR__) . '/assets',
            $this->assetMap(),
        ));
```

In `boot()`, inside the `if ($container->has(TemplateEngineInterface::class))` block, replace the `folio_admin_css_url` global registration with the `folio_asset` function:

```php
            // Single asset URL seam: folio_asset('admin.css') -> versioned URL.
            // Content-hash version so immutable caches bust on edit. A future
            // asset-publishing system can swap this one resolver.
            $assetsDir = dirname(__DIR__) . '/assets';
            $assetMap = $this->assetMap();
            $prefix = rtrim($this->prefix($app), '/');
            $engine->addFunction(new \Preflow\View\TemplateFunctionDefinition(
                name: 'folio_asset',
                callable: function (string $file) use ($prefix, $assetsDir, $assetMap): string {
                    $rel = $assetMap[$file] ?? $file;
                    $path = $assetsDir . '/' . $rel;
                    $v = is_file($path) ? substr(hash_file('xxh3', $path), 0, 12) : 'dev';
                    return $prefix . '/_assets/' . $file . '?v=' . $v;
                },
                isSafe: false,
            ));
```

(Remove the old `$cssPath`/`$version`/`addGlobal('folio_admin_css_url', ...)` lines entirely.)

- [ ] **Step 7: Update the templates to use `folio_asset`**

In `packages/folio/templates/admin/_layout.twig`, replace the stylesheet link line:

```twig
    <link rel="stylesheet" href="{{ folio_asset('admin.css') }}">
```

In `packages/folio/templates/admin/login.twig`, replace its `<link rel="stylesheet" href="{{ folio_admin_css_url }}">` line with:

```twig
    <link rel="stylesheet" href="{{ folio_asset('admin.css') }}">
```

- [ ] **Step 8: Run the affected suites**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AssetControllerTest.php packages/folio/tests/Routing/FolioRoutesTest.php packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS. The integration `test_admin_stylesheet_is_served` still gets `200 text/css` (now via `serve()`), and `test_admin_shell_renders_sidebar_stylesheet_and_toggle` still finds `/folio/_assets/admin.css?v=` in the body (now produced by `folio_asset`).

- [ ] **Step 9: Commit**

```bash
git add packages/folio/src/Http/AssetController.php packages/folio/src/Routing/FolioRoutes.php packages/folio/src/FolioServiceProvider.php packages/folio/templates/admin/_layout.twig packages/folio/templates/admin/login.twig packages/folio/tests/Http/AssetControllerTest.php packages/folio/tests/Routing/FolioRoutesTest.php
git commit -m "feat(folio): generalize asset route to allowlist + folio_asset() seam"
```

---

### Task 2: Vendor Trix

**Files:**
- Create: `packages/folio/assets/vendor/trix.umd.min.js`, `packages/folio/assets/vendor/trix.css`, `packages/folio/assets/vendor/VENDOR.md`
- Modify: `packages/folio/src/FolioServiceProvider.php` (add trix entries to `assetMap()`)
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Consumes: the asset route + `assetMap()` from Task 1.
- Produces: `assetMap()` additionally maps `trix.js => vendor/trix.umd.min.js` and `trix.css => vendor/trix.css`; both served via `{prefix}/_assets/trix.js` and `/trix.css`.

- [ ] **Step 1: Download the pinned Trix bundle**

```bash
mkdir -p packages/folio/assets/vendor
curl -sSL -o packages/folio/assets/vendor/trix.umd.min.js https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.umd.min.js
curl -sSL -o packages/folio/assets/vendor/trix.css https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.css
```

Verify both downloaded (not an error page):

Run: `head -c 40 packages/folio/assets/vendor/trix.umd.min.js && echo && head -c 40 packages/folio/assets/vendor/trix.css && wc -c packages/folio/assets/vendor/trix.*`
Expected: the JS starts with a `/* Trix 2.1.19 ... */` comment, the CSS with `@charset "UTF-8";` / `trix-editor`, and both files are non-trivial in size (JS ~200KB, CSS ~20KB). If either is tiny or HTML, the download failed — stop and report.

- [ ] **Step 2: Record provenance**

Create `packages/folio/assets/vendor/VENDOR.md`:

```markdown
# Vendored assets

These are prebuilt third-party bundles committed verbatim (no build step in this
repo). Re-fetch from the pinned version when upgrading.

## Trix

- Version: 2.1.19
- License: MIT (Basecamp / 37signals)
- Source:
  - trix.umd.min.js — https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.umd.min.js
  - trix.css        — https://cdn.jsdelivr.net/npm/trix@2.1.19/dist/trix.css
- Fetched: 2026-06-19
```

- [ ] **Step 3: Add trix entries to the asset map**

In `packages/folio/src/FolioServiceProvider.php`, update `assetMap()`:

```php
    private function assetMap(): array
    {
        return [
            'admin.css' => 'admin.css',
            'trix.css' => 'vendor/trix.css',
            'trix.js' => 'vendor/trix.umd.min.js',
        ];
    }
```

- [ ] **Step 4: Write the failing serving test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_vendored_trix_assets_served(): void
    {
        $js = $this->get('/folio/_assets/trix.js');
        $this->assertSame(200, $js->getStatusCode());
        $this->assertStringContainsString('text/javascript', $js->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Trix', (string) $js->getBody());

        $css = $this->get('/folio/_assets/trix.css');
        $this->assertSame(200, $css->getStatusCode());
        $this->assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('trix-editor', (string) $css->getBody());
    }
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_vendored_trix_assets_served`
Expected: PASS (depends on Steps 1 + 3).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/assets/vendor/ packages/folio/src/FolioServiceProvider.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): vendor Trix 2.1.19 and serve via asset route"
```

---

### Task 3: RichTextFieldType + sanitizer

**Files:**
- Modify: `packages/folio/composer.json` (add `symfony/html-sanitizer`)
- Create: `packages/folio/src/Field/Types/RichTextFieldType.php`
- Modify: `packages/folio/src/FolioServiceProvider.php` (register the type)
- Test: `packages/folio/tests/Field/RichTextFieldTypeTest.php` (new)

**Interfaces:**
- Consumes: `FieldType`/`FieldContext` (Phase 1); `Preflow\Form\FieldRenderer` (`humanize`); `Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface`.
- Produces: `RichTextFieldType` (key `richtext`): `renderEditor` emits a `form-group` with a hidden `<input>` + a `<trix-editor input="folio-rt-{name}">`; `normalizeInput` sanitizes submitted HTML; `toStorage`/`fromStorage` passthrough; `renderFrontend` returns re-sanitized HTML (safe for `|raw`); `assets()` returns `['trix.css', 'trix.js']`; `rules()` returns `[]`. Registered in the `FieldTypeRegistry` provider binding.

- [ ] **Step 1: Add the dependency**

In `packages/folio/composer.json`, add to the `require` block (after `nyholm/psr7`):

```json
        "symfony/html-sanitizer": "^7.0"
```

Install it into the root vendor (the suite runs from root):

Run: `composer update preflow/folio --with-all-dependencies --no-interaction`
Then verify: `php -r 'require "vendor/autoload.php"; var_dump(interface_exists("Symfony\\Component\\HtmlSanitizer\\HtmlSanitizerInterface"));'`
Expected: `bool(true)`.

- [ ] **Step 2: Write the failing test**

Create `packages/folio/tests/Field/RichTextFieldTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\RichTextFieldType;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class RichTextFieldTypeTest extends TestCase
{
    private function type(): RichTextFieldType
    {
        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())->allowSafeElements()->allowRelativeLinks()->allowRelativeMedias(),
        );
        return new RichTextFieldType($sanitizer);
    }

    public function test_key_and_assets(): void
    {
        $t = $this->type();
        $this->assertSame('richtext', $t->key());
        $this->assertSame(['trix.css', 'trix.js'], $t->assets());
    }

    public function test_render_editor_emits_trix_and_hidden_input(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'body', label: 'Body', value: '<p>hi</p>',
        ));
        $this->assertStringContainsString('<trix-editor input="folio-rt-body"', $html);
        $this->assertStringContainsString('<input type="hidden" id="folio-rt-body" name="body"', $html);
        $this->assertStringContainsString('&lt;p&gt;hi&lt;/p&gt;', $html); // value escaped into the attribute
        $this->assertStringContainsString('Body', $html);
    }

    public function test_normalize_strips_scripts_keeps_safe_markup(): void
    {
        $clean = (string) $this->type()->normalizeInput('<p><strong>ok</strong></p><script>alert(1)</script>', []);
        $this->assertStringContainsString('<strong>ok</strong>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }

    public function test_render_frontend_is_sanitized(): void
    {
        $out = $this->type()->renderFrontend('<p>hi</p><script>alert(1)</script>', []);
        $this->assertStringContainsString('<p>hi</p>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function test_storage_roundtrip_passthrough(): void
    {
        $t = $this->type();
        $this->assertSame('<p>x</p>', $t->toStorage('<p>x</p>'));
        $this->assertSame('<p>x</p>', $t->fromStorage('<p>x</p>'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/RichTextFieldTypeTest.php`
Expected: FAIL — `Preflow\Folio\Field\Types\RichTextFieldType` does not exist.

- [ ] **Step 4: Create the field type**

Create `packages/folio/src/Field/Types/RichTextFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Form\FieldRenderer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Rich-text field backed by the vendored Trix editor. HTML is sanitized on save
 * AND re-sanitized on frontend render (defense in depth), so `|raw` output is
 * always safe. Trix self-initializes from the `<trix-editor input="ID">` element
 * bound to the hidden input — no custom admin JS required.
 */
final class RichTextFieldType implements FieldType
{
    public function __construct(
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly FieldRenderer $renderer = new FieldRenderer(),
    ) {}

    public function key(): string
    {
        return 'richtext';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $name = $ctx->name;
        $id = 'folio-rt-' . $name;
        $value = (string) ($ctx->value ?? '');
        $label = $ctx->label ?? $this->renderer->humanize($name);
        $hasError = $ctx->errors !== [];

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $wrapperClass = 'form-group' . ($hasError ? ' has-error' : '');

        $html = '<div class="' . $wrapperClass . '">' . "\n";
        $html .= '  <label for="' . $e($id) . '">' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</label>' . "\n";
        $html .= '  <input type="hidden" id="' . $e($id) . '" name="' . $e($name) . '" value="' . $e($value) . '">' . "\n";
        $html .= '  <trix-editor input="' . $e($id) . '" class="folio-richtext"></trix-editor>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->sanitizer->sanitize(is_string($raw) ? $raw : '');
    }

    public function toStorage(mixed $value): mixed
    {
        return $value;
    }

    public function fromStorage(mixed $value): mixed
    {
        return $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        // Re-sanitize defensively: stored values should already be clean, but
        // this guarantees safe `|raw` output regardless of provenance.
        return $this->sanitizer->sanitize(is_string($value) ? $value : '');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['trix.css', 'trix.js'];
    }
}
```

- [ ] **Step 5: Register the type in the provider**

In `packages/folio/src/FolioServiceProvider.php`, add imports:

```php
use Preflow\Folio\Field\Types\RichTextFieldType;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
```

In the `FieldTypeRegistry` bind closure (added in Phase 1), register the rich-text type after the scalar types and before `return $registry;`:

```php
            $registry->register(new RichTextFieldType(new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->allowRelativeLinks()
                    ->allowRelativeMedias(),
            )));
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Field/RichTextFieldTypeTest.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add packages/folio/composer.json composer.lock packages/folio/src/Field/Types/RichTextFieldType.php packages/folio/src/FolioServiceProvider.php packages/folio/tests/Field/RichTextFieldTypeTest.php
git commit -m "feat(folio): richtext field type backed by Trix + html sanitizer"
```

---

### Task 4: Per-page editor assets in the admin form

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`
- Modify: `packages/folio/templates/admin/_layout.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify, incl. the scaffold model + the Phase-1 registry assertion)

**Interfaces:**
- Consumes: `FieldType::assets()` (Phase 1 / Task 3); `folio_asset()` (Task 1).
- Produces: `AdminController::form()` passes `editor_assets` (a deduped list of asset filenames unioned across the page's field types) to the template; `_layout.twig` emits a `<link>`/`<script defer>` per asset in `<head>`.

- [ ] **Step 1: Update the integration scaffold + Phase-1 assertion, add the failing test**

In `packages/folio/tests/Integration/FolioAppTest.php`:

(a) In the `scaffold()` method's `config/models/page.json`, change the `body` field type from `text` to `richtext` (leave title/slug/status as-is):

```php
                'body'   => ['type' => 'richtext'],
```

(b) The Phase-1 test `test_create_form_renders_fields_via_registry` asserts `<textarea name="body"`; body is now rich text, so update that one assertion line to:

```php
        $this->assertStringContainsString('<trix-editor input="folio-rt-body"', $body);
```

(Keep its `name="title"` / `type="text"` assertions.)

(c) Add this new test method:

```php
    public function test_richtext_form_includes_trix_assets_and_editor(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        $this->assertStringContainsString('<trix-editor input="folio-rt-body"', $body);
        $this->assertStringContainsString('/folio/_assets/trix.js?v=', $body);
        $this->assertStringContainsString('/folio/_assets/trix.css?v=', $body);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter "test_richtext_form_includes_trix_assets_and_editor|test_create_form_renders_fields_via_registry"`
Expected: FAIL — the trix asset `<link>`/`<script>` are not emitted yet (the editor renders, but assets aren't unioned into the layout).

- [ ] **Step 3: Union field assets in `AdminController::form()`**

In `packages/folio/src/Http/AdminController.php`, in `form()`, collect assets while building fields and pass them to the template. Replace the field-building loop + render call with:

```php
        $fields = [];
        $editorAssets = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $ctx = new FieldContext(
                name: $name,
                label: $fieldDef->label,
                help: $fieldDef->help,
                value: $fieldType->fromStorage($values[$name] ?? null),
                errors: $errors[$name] ?? [],
                config: $fieldDef->config,
                required: in_array('required', $fieldDef->validate, true),
            );
            $fields[] = ['name' => $name, 'html' => $fieldType->renderEditor($ctx)];
            foreach ($fieldType->assets() as $asset) {
                $editorAssets[$asset] = true;
            }
        }

        $html = $this->engine->render('@folio/admin/form.twig', [
            'prefix' => $this->prefix,
            'type' => $type,
            'label' => $this->labelFor($type),
            'types' => $this->catalog->all(),
            'heading' => $heading,
            'action' => $action,
            'csrf' => $csrf,
            'fields' => $fields,
            'editor_assets' => array_keys($editorAssets),
        ]);

        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
```

- [ ] **Step 4: Emit editor assets in the layout head**

In `packages/folio/templates/admin/_layout.twig`, immediately after the `<link rel="stylesheet" href="{{ folio_asset('admin.css') }}">` line (still inside `<head>`), add:

```twig
    {% for a in editor_assets|default([]) %}
        {%- if a ends with '.css' %}<link rel="stylesheet" href="{{ folio_asset(a) }}">
        {%- elseif a ends with '.js' %}<script src="{{ folio_asset(a) }}" defer></script>
        {%- endif %}
    {% endfor %}
```

- [ ] **Step 5: Run the integration suite**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — including the new asset/editor test, the updated registry test, and the existing create/edit/frontend tests (body is now richtext; `test_create_then_render_on_frontend` still asserts the title `About Us`, and rich-text sanitization keeps the `<p>` body).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/templates/admin/_layout.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): include per-field editor assets in the admin layout"
```

---

### Task 5: Registry-driven frontend rendering

**Files:**
- Modify: `packages/folio/src/Http/FrontendController.php`
- Modify: `packages/folio/src/FolioServiceProvider.php` (inject registry into FrontendController)
- Modify: `packages/folio/templates/frontend/page.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Consumes: `FieldTypeRegistry` (`get()`), `FieldType::fromStorage`/`renderFrontend`; `DynamicRecord::getType()`/`get()`.
- Produces: `FrontendController` renders each of the record's fields via the registry into a `rendered` map (`field name => safe HTML`) passed to `page.twig` alongside `record`. `page.twig` outputs the rich-text body via `rendered.body`.

- [ ] **Step 1: Write the failing test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_richtext_frontend_renders_sanitized_html(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $app->handle($f->createServerRequest('POST', '/folio/page')->withParsedBody([
            'title' => 'Post', 'slug' => 'post',
            'body' => '<p>safe</p><script>alert(1)</script>', 'status' => 'published',
        ]));

        $html = (string) $app->handle($f->createServerRequest('GET', '/post'))->getBody();
        $this->assertStringContainsString('<p>safe</p>', $html);   // rich HTML rendered raw
        $this->assertStringNotContainsString('<script', $html);    // sanitized end-to-end
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_richtext_frontend_renders_sanitized_html`
Expected: FAIL — `page.twig` renders `record.body|raw`; body is sanitized on save so `<script>` is already gone, BUT this test also requires the registry-driven `rendered` map path. (If it passes solely due to save-time sanitization, still implement Steps 3-5 — the registry-driven render is the deliverable and the later full-suite run is the gate.)

- [ ] **Step 3: Make `FrontendController` build the `rendered` map**

Replace the contents of `packages/folio/src/Http/FrontendController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FrontendController
{
    public function __construct(
        private readonly FrontendResolver $resolver,
        private readonly TemplateEngineInterface $engine,
        private readonly FieldTypeRegistry $fieldTypes,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        $record = $this->resolver->resolve($path);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        $typeDef = $record->getType();
        $rendered = [];
        foreach ($typeDef->fields as $name => $fieldDef) {
            $fieldType = $this->fieldTypes->get($fieldDef->type);
            $rendered[$name] = $fieldType->renderFrontend(
                $fieldType->fromStorage($record->get($name)),
                $fieldDef->config,
            );
        }

        $html = $this->engine->render('@folio/frontend/page.twig', [
            'record' => $record->toArray(),
            'rendered' => $rendered,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
```

- [ ] **Step 4: Inject the registry into the FrontendController binding**

In `packages/folio/src/FolioServiceProvider.php`, update the `FrontendController` binding to pass the registry:

```php
        $container->bind(FrontendController::class, fn (Container $c) => new FrontendController(
            $c->get(FrontendResolver::class),
            $c->get(TemplateEngineInterface::class),
            $c->get(FieldTypeRegistry::class),
        ));
```

- [ ] **Step 5: Render via the `rendered` map in the template**

Replace `packages/folio/templates/frontend/page.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ record.title }}</title></head>
<body>
    <article>
        <h1>{{ record.title }}</h1>
        <div>{{ rendered.body|default('')|raw }}</div>
    </article>
</body>
</html>
```

- [ ] **Step 6: Run the integration suite**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — including the new frontend sanitization test and the existing `test_create_then_render_on_frontend` (title still rendered; body now via `rendered.body`).

- [ ] **Step 7: Commit**

```bash
git add packages/folio/src/Http/FrontendController.php packages/folio/src/FolioServiceProvider.php packages/folio/templates/frontend/page.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): registry-driven frontend field rendering"
```

---

### Task 6: Demo showcase + full-suite verification

**Files:**
- Modify: `examples/folio-demo/config/models/page.json`
- Test: whole repo suite

**Interfaces:**
- Consumes: the richtext field type (Task 3) + frontend rendering (Task 5).

- [ ] **Step 1: Make the demo `body` rich text**

In `examples/folio-demo/config/models/page.json`, change the `body` field to richtext (keep its label; update the help to reflect rich text). The `fields` object becomes:

```json
    "fields": {
        "title": { "type": "string", "validate": ["required"] },
        "slug": { "type": "string", "validate": ["required"] },
        "body": { "type": "richtext", "label": "Body content", "help": "Rich text — formatting is preserved and sanitized on save." },
        "status": { "type": "string" }
    }
```

- [ ] **Step 2: Verify the demo smoke test still passes**

Run: `vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS (2 tests) — the demo app still boots and serves `/folio`.

- [ ] **Step 3: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green. Only expected non-failures are the pre-existing PHPUnit deprecations + 1 skip. No test failures. If anything fails, investigate before committing.

- [ ] **Step 4: Commit**

```bash
git add examples/folio-demo/config/models/page.json
git commit -m "docs(folio): demo body as a rich-text field"
```

---

## Self-Review

**Spec coverage (Phase 2 portion of the field/editor spec):**
- Rich-text field type backed by vendored prebuilt Trix (self-hosted, no external request, no build) → Tasks 2, 3.
- HTML sanitized on save (`symfony/html-sanitizer`) AND re-sanitized on render (defense in depth) → Task 3.
- Asset-route generalization + single `folio_asset()` seam (deferred from Phase 1) → Task 1.
- Per-page editor asset inclusion via `assets()` union into the layout (plain markup, action-mode safe) → Task 4.
- Registry-driven frontend rendering (rich text emits safe HTML; scalars escape) → Task 5.
- No admin JS written (Trix self-initializes; `admin.js` correctly deferred to Phase 5) → Global Constraints + Task 4.
- Demo showcases rich text → Task 6.
- Out of scope this phase (per spec): asset uploads/attachments (Phase 3), relation (Phase 4), matrix (Phase 5).

**Placeholder scan:** No `TBD`/`TODO`. The Task 5 Step 2 note explains a benign possibility (the test may pass partly via save-time sanitization) and states the real deliverable + gate — not a deferral.

**Type consistency:** `AssetController::__construct(string $baseDir, array $allowlist)` + `serve()` consistent across Tasks 1-2 and the route handler string `AssetController@serve`. `folio_asset(string)` used identically in Tasks 1, 4. `assetMap()` shape (flat filename → relative path) consistent in Tasks 1, 2. `RichTextFieldType` key `richtext`, assets `['trix.css','trix.js']`, and the `folio-rt-{name}` id/hidden-input convention consistent across Tasks 3, 4. `FrontendController` constructor (resolver, engine, fieldTypes) matches its provider binding (Task 5). `editor_assets` template var produced in Task 4 (AdminController) and consumed in `_layout.twig` (Task 4). `rendered` map produced in Task 5 (FrontendController) and consumed in `page.twig` (Task 5).
