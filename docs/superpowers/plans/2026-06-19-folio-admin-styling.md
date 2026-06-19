# Folio Admin Styling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Folio admin (`/folio`) a clean, professional Stripe/Notion-warm look with an emerald accent and dark mode baked in, delivered as a single hand-written stylesheet with no build step.

**Architecture:** A real `.css` file ships in the package and is served by a small `AssetController` route (`GET {prefix}/_assets/admin.css`); the `<link>` URL is produced in exactly one place (a `folio_admin_css_url` Twig global, content-hash versioned) so a future asset-publishing system can swap delivery without touching templates. `_layout.twig` becomes a sidebar app shell; dashboard/list/form are restyled against existing markup hooks; a standalone login template is added. Dark mode uses CSS custom properties with `prefers-color-scheme` defaults plus a `data-theme` override toggled by ~15 lines of inline vanilla JS.

**Tech Stack:** PHP 8.5, Twig (`preflow/twig`), PSR-7 (`nyholm/psr7`), PHPUnit 11, plain modern CSS (custom properties), vanilla JS. No npm, no bundler, no external runtime deps.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step, no external dependencies, no asset-publishing requirement** — the admin must work drop-in.
- **System UI font stack only** — no web fonts, no external requests.
- **Emerald accent**, warm-neutral gray scale; accent is the only color besides danger/success states.
- **No emojis** anywhere in UI copy or code.
- **Action-mode responses bypass `AssetCollector`** (`Application::injectAssets` runs only for component routes). Therefore the stylesheet link and theme scripts are plain markup in `_layout.twig` — do NOT use `{% apply css %}`/`{% apply js %}` for them.
- **Single URL seam:** templates reference the stylesheet only via the `folio_admin_css_url` Twig global. Do NOT overload `asset_url()` (that seam belongs to the future publisher).
- **Overridability:** every template stays under the `@folio` namespace so host apps can override via `resources/folio/`.
- **Test command:** `vendor/bin/phpunit <path>` from repo root (`/Users/smyr/Sites/gbits/flopp`). Config is `phpunit.xml` at the root.
- Templates render under **strict Twig** (`APP_DEBUG=1` in integration tests) — guard undefined-variable access with `|default(...)`.

## File Structure

- `packages/folio/assets/admin.css` — **new**. The stylesheet (source of truth, publish-ready).
- `packages/folio/src/Http/AssetController.php` — **new**. Serves `admin.css` from disk with cache headers.
- `packages/folio/src/Routing/FolioRoutes.php` — **modify**. Add the asset route entry.
- `packages/folio/src/FolioServiceProvider.php` — **modify**. Bind `AssetController`, register the asset route, register the `folio_admin_css_url` global.
- `packages/folio/templates/admin/_layout.twig` — **modify**. Sidebar app shell + theme scripts + css link.
- `packages/folio/templates/admin/dashboard.twig` — **modify**. Card grid.
- `packages/folio/templates/admin/list.twig` — **modify**. Data table + empty state + Delete action.
- `packages/folio/templates/admin/form.twig` — **modify**. Styled form + action bar + Cancel.
- `packages/folio/templates/admin/login.twig` — **new**. Standalone centered login card.
- `packages/folio/src/Http/AdminController.php` — **modify**. Dashboard passes per-type record counts.
- Tests: `packages/folio/tests/Assets/AdminCssTest.php` (new), `packages/folio/tests/Http/AssetControllerTest.php` (new), `packages/folio/tests/Routing/FolioRoutesTest.php` (modify), `packages/folio/tests/Integration/FolioAppTest.php` (modify), `packages/folio/tests/TemplatesExistTest.php` (modify).

---

### Task 1: Stylesheet — tokens, dark mode, and full admin styling

**Files:**
- Create: `packages/folio/assets/admin.css`
- Test: `packages/folio/tests/Assets/AdminCssTest.php`

**Interfaces:**
- Produces: a stylesheet defining the token contract used by all templates. Class hooks: `.folio-shell`, `.folio-sidebar`, `.folio-brand`, `.folio-nav`, `.folio-nav a` (+ `.active`), `.folio-theme-toggle`, `.folio-topbar`, `.folio-topbar h1`, `.folio-actions`, `.folio-content`, `.folio-card-grid`, `.folio-card`, `.folio-table`, `.folio-empty`, `.btn`/`.btn-primary`/`.btn-secondary`/`.btn-ghost`/`.btn-danger`, `.folio-form`, `.folio-form-actions`, `.folio-alert`/`.folio-alert-error`/`.folio-alert-success`, `.folio-auth`, `.folio-auth-card`. Reuses existing form hooks from `preflow/form`: `.form-group`, `.form-required`, `.form-help`, `.form-error`, `.has-error`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Assets/AdminCssTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\TestCase;

final class AdminCssTest extends TestCase
{
    private function css(): string
    {
        $path = dirname(__DIR__, 2) . '/assets/admin.css';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function test_defines_base_and_dark_token_sets(): void
    {
        $css = $this->css();
        $this->assertStringContainsString(':root', $css);
        $this->assertStringContainsString('[data-theme="dark"]', $css);
        $this->assertStringContainsString('prefers-color-scheme: dark', $css);
    }

    public function test_defines_emerald_accent_and_core_tokens(): void
    {
        $css = $this->css();
        foreach (['--c-accent', '--c-bg', '--c-surface', '--c-border', '--c-text', '--font-sans'] as $token) {
            $this->assertStringContainsString($token, $css);
        }
    }

    public function test_styles_shell_and_reused_form_hooks(): void
    {
        $css = $this->css();
        foreach (['.folio-shell', '.folio-sidebar', '.folio-table', '.folio-card', '.btn-primary', '.form-group', '.has-error'] as $sel) {
            $this->assertStringContainsString($sel, $css);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminCssTest.php`
Expected: FAIL — `assertFileExists` fails because `admin.css` does not exist yet.

- [ ] **Step 3: Create the stylesheet**

Create `packages/folio/assets/admin.css`:

```css
/* Folio admin — clean, warm-minimal, emerald accent, dark mode baked in.
   Tokens only; components never branch on theme. Default follows the OS via
   prefers-color-scheme; [data-theme] overrides explicitly. */

:root {
  --font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  --r: 8px;
  --r-sm: 6px;
  --shadow-sm: 0 1px 2px rgba(0, 0, 0, .04), 0 1px 3px rgba(0, 0, 0, .06);
  --shadow-md: 0 4px 12px rgba(0, 0, 0, .08);

  /* warm light theme */
  --c-bg: #faf9f8;
  --c-surface: #ffffff;
  --c-surface-raised: #ffffff;
  --c-sidebar-bg: #f4f2f0;
  --c-border: #e7e5e3;
  --c-row-hover: #f6f5f3;
  --c-text: #1c1b1a;
  --c-text-muted: #6f6b66;
  --c-accent: #059669;
  --c-accent-hover: #047857;
  --c-accent-fg: #ffffff;
  --c-danger: #dc2626;
  --c-danger-fg: #ffffff;
  --c-success: #059669;
  --c-focus-ring: rgba(5, 150, 105, .35);
}

/* dark tokens applied as OS default unless the user forced light */
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --c-bg: #1a1917;
    --c-surface: #232220;
    --c-surface-raised: #2a2825;
    --c-sidebar-bg: #1f1e1c;
    --c-border: #38352f;
    --c-row-hover: #2a2825;
    --c-text: #ece9e4;
    --c-text-muted: #a39e96;
    --c-accent: #10b981;
    --c-accent-hover: #34d399;
    --c-accent-fg: #07120d;
    --c-danger: #f87171;
    --c-danger-fg: #1a1917;
    --c-success: #34d399;
    --c-focus-ring: rgba(16, 185, 129, .4);
  }
}

/* dark tokens when the user explicitly chose dark */
[data-theme="dark"] {
  --c-bg: #1a1917;
  --c-surface: #232220;
  --c-surface-raised: #2a2825;
  --c-sidebar-bg: #1f1e1c;
  --c-border: #38352f;
  --c-row-hover: #2a2825;
  --c-text: #ece9e4;
  --c-text-muted: #a39e96;
  --c-accent: #10b981;
  --c-accent-hover: #34d399;
  --c-accent-fg: #07120d;
  --c-danger: #f87171;
  --c-danger-fg: #1a1917;
  --c-success: #34d399;
  --c-focus-ring: rgba(16, 185, 129, .4);
}

*, *::before, *::after { box-sizing: border-box; }

html, body { height: 100%; }

body {
  margin: 0;
  font-family: var(--font-sans);
  font-size: 14px;
  line-height: 1.5;
  color: var(--c-text);
  background: var(--c-bg);
  -webkit-font-smoothing: antialiased;
}

a { color: var(--c-accent); text-decoration: none; }
a:hover { color: var(--c-accent-hover); }

h1 { font-size: 1.25rem; font-weight: 650; letter-spacing: -.01em; margin: 0; }

/* ---- app shell ---- */
.folio-shell {
  display: grid;
  grid-template-columns: 248px 1fr;
  min-height: 100vh;
}

.folio-sidebar {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 20px 14px;
  background: var(--c-sidebar-bg);
  border-right: 1px solid var(--c-border);
}

.folio-brand {
  font-weight: 700;
  letter-spacing: -.02em;
  font-size: 1.05rem;
  padding: 6px 10px 16px;
  color: var(--c-text);
}

.folio-nav { display: flex; flex-direction: column; gap: 2px; }
.folio-nav a {
  display: block;
  padding: 7px 10px;
  border-radius: var(--r-sm);
  color: var(--c-text-muted);
  font-weight: 500;
}
.folio-nav a:hover { background: var(--c-row-hover); color: var(--c-text); }
.folio-nav a.active { background: var(--c-accent); color: var(--c-accent-fg); }

.folio-sidebar-foot { margin-top: auto; padding-top: 12px; }
.folio-theme-toggle {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 7px 10px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-sm);
  background: var(--c-surface);
  color: var(--c-text-muted);
  font: inherit;
  cursor: pointer;
}
.folio-theme-toggle:hover { color: var(--c-text); border-color: var(--c-text-muted); }
.folio-theme-toggle svg { width: 16px; height: 16px; }

/* ---- topbar + content ---- */
.folio-main { display: flex; flex-direction: column; min-width: 0; }
.folio-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 28px;
  border-bottom: 1px solid var(--c-border);
  background: var(--c-surface);
}
.folio-actions { display: flex; gap: 8px; }
.folio-content { padding: 28px; max-width: 920px; width: 100%; }

/* ---- buttons ---- */
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 14px;
  border-radius: var(--r-sm);
  border: 1px solid transparent;
  font: inherit;
  font-weight: 550;
  cursor: pointer;
  line-height: 1.2;
}
.btn:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--c-focus-ring); }
.btn-primary { background: var(--c-accent); color: var(--c-accent-fg); }
.btn-primary:hover { background: var(--c-accent-hover); color: var(--c-accent-fg); }
.btn-secondary { background: var(--c-surface); color: var(--c-text); border-color: var(--c-border); }
.btn-secondary:hover { border-color: var(--c-text-muted); }
.btn-ghost { background: transparent; color: var(--c-text-muted); }
.btn-ghost:hover { background: var(--c-row-hover); color: var(--c-text); }
.btn-danger { background: transparent; color: var(--c-danger); border-color: var(--c-border); }
.btn-danger:hover { background: var(--c-danger); color: var(--c-danger-fg); border-color: var(--c-danger); }

/* ---- dashboard cards ---- */
.folio-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
  margin-top: 20px;
}
.folio-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px;
  border: 1px solid var(--c-border);
  border-radius: var(--r);
  background: var(--c-surface);
  box-shadow: var(--shadow-sm);
}
.folio-card:hover { border-color: var(--c-accent); }
.folio-card-label { font-weight: 600; color: var(--c-text); }
.folio-card-count { color: var(--c-text-muted); font-size: .85rem; }

/* ---- table ---- */
.folio-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
.folio-table thead th {
  text-align: left;
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .04em;
  color: var(--c-text-muted);
  font-weight: 600;
  padding: 0 14px 10px;
  border-bottom: 1px solid var(--c-border);
}
.folio-table tbody td { padding: 12px 14px; border-bottom: 1px solid var(--c-border); }
.folio-table tbody tr:hover { background: var(--c-row-hover); }
.folio-table .folio-row-actions { display: flex; gap: 8px; justify-content: flex-end; }
.folio-table .folio-row-actions form { display: inline; margin: 0; }

.folio-empty {
  margin-top: 24px;
  padding: 48px;
  text-align: center;
  color: var(--c-text-muted);
  border: 1px dashed var(--c-border);
  border-radius: var(--r);
}
.folio-empty p { margin: 0 0 14px; }

/* ---- forms (reuses preflow/form hooks) ---- */
.folio-form { margin-top: 20px; max-width: 560px; }
.form-group { margin-bottom: 18px; }
.form-group label {
  display: block;
  margin-bottom: 6px;
  font-weight: 550;
  color: var(--c-text);
}
.form-required { color: var(--c-danger); margin-left: 2px; }
.form-group input,
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 8px 11px;
  font: inherit;
  color: var(--c-text);
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-sm);
}
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--c-accent);
  box-shadow: 0 0 0 3px var(--c-focus-ring);
}
.form-group textarea { min-height: 140px; resize: vertical; }
.form-help { display: block; margin-top: 5px; color: var(--c-text-muted); font-size: .82rem; }
.form-error { margin-top: 5px; color: var(--c-danger); font-size: .82rem; }
.has-error input,
.has-error textarea,
.has-error select { border-color: var(--c-danger); }

.folio-form-actions {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-top: 24px;
  padding-top: 18px;
  border-top: 1px solid var(--c-border);
}

/* ---- alerts ---- */
.folio-alert {
  padding: 11px 14px;
  border-radius: var(--r-sm);
  border: 1px solid var(--c-border);
  margin-bottom: 18px;
}
.folio-alert-error { border-color: var(--c-danger); color: var(--c-danger); }
.folio-alert-success { border-color: var(--c-success); color: var(--c-success); }

/* ---- login ---- */
.folio-auth {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--c-bg);
}
.folio-auth-card {
  width: 100%;
  max-width: 360px;
  padding: 32px;
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r);
  box-shadow: var(--shadow-md);
}
.folio-auth-card .folio-brand { padding: 0 0 20px; text-align: center; }
.folio-auth-card .btn-primary { width: 100%; justify-content: center; margin-top: 8px; }

/* ---- responsive: collapse sidebar ---- */
@media (max-width: 720px) {
  .folio-shell { grid-template-columns: 1fr; }
  .folio-sidebar {
    flex-direction: row;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    border-right: none;
    border-bottom: 1px solid var(--c-border);
    padding: 12px 16px;
  }
  .folio-brand { padding: 0 8px 0 0; }
  .folio-nav { flex-direction: row; flex-wrap: wrap; }
  .folio-sidebar-foot { margin-top: 0; padding-top: 0; margin-left: auto; }
  .folio-theme-toggle { width: auto; }
  .folio-content { padding: 18px; }
  .folio-topbar { padding: 14px 18px; }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminCssTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/assets/admin.css packages/folio/tests/Assets/AdminCssTest.php
git commit -m "feat(folio): admin stylesheet with warm tokens, emerald accent, dark mode"
```

---

### Task 2: Serve the stylesheet — AssetController, route, version global

**Files:**
- Create: `packages/folio/src/Http/AssetController.php`
- Modify: `packages/folio/src/Routing/FolioRoutes.php`
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Http/AssetControllerTest.php`, `packages/folio/tests/Routing/FolioRoutesTest.php`, `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: `packages/folio/assets/admin.css` (Task 1). `Nyholm\Psr7\Response`, `Psr\Http\Message\{ResponseInterface,ServerRequestInterface}`. `Preflow\Folio\Routing\PatternCompiler::compile()`. `Preflow\View\TemplateEngineInterface::addGlobal()`.
- Produces:
  - `AssetController::__construct(string $cssPath)` and `AssetController::adminCss(ServerRequestInterface $request): ResponseInterface` — 200 `text/css` with the file body and `Cache-Control` when the file exists, else 404.
  - `FolioRoutes::admin()` additionally emits a `GET {prefix}/_assets/admin.css` entry, handler `Preflow\Folio\Http\AssetController@adminCss`, appended last.
  - Twig global `folio_admin_css_url` = `{prefix}/_assets/admin.css?v=<12-char xxh3 of the css file>` (used by templates in Tasks 3–7).

- [ ] **Step 1: Write the failing controller test**

Create `packages/folio/tests/Http/AssetControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AssetController;

final class AssetControllerTest extends TestCase
{
    public function test_serves_existing_css_with_type_and_cache_headers(): void
    {
        $path = dirname(__DIR__, 2) . '/assets/admin.css';
        $controller = new AssetController($path);

        $res = $controller->adminCss((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('max-age', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }

    public function test_missing_file_returns_404(): void
    {
        $controller = new AssetController('/no/such/admin.css');
        $res = $controller->adminCss((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));
        $this->assertSame(404, $res->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AssetControllerTest.php`
Expected: FAIL — class `Preflow\Folio\Http\AssetController` not found.

- [ ] **Step 3: Create the controller**

Create `packages/folio/src/Http/AssetController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves Folio's package-owned admin stylesheet from disk. The URL is
 * content-hash versioned (see FolioServiceProvider), so the response is safe to
 * cache immutably. Keeping the CSS a real file (not a PHP string) leaves it
 * publish-ready for a future asset-publishing system.
 */
final class AssetController
{
    public function __construct(private readonly string $cssPath) {}

    public function adminCss(ServerRequestInterface $request): ResponseInterface
    {
        if (!is_file($this->cssPath)) {
            return new Response(404, [], 'Not found');
        }

        return new Response(
            200,
            [
                'Content-Type' => 'text/css; charset=UTF-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            (string) file_get_contents($this->cssPath),
        );
    }
}
```

- [ ] **Step 4: Run controller test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Http/AssetControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Write the failing route test**

Add this method to `packages/folio/tests/Routing/FolioRoutesTest.php` (inside the class):

```php
    public function test_admin_includes_stylesheet_asset_route(): void
    {
        $entries = FolioRoutes::admin('/folio');

        $match = null;
        foreach ($entries as $e) {
            if ($e->pattern === '/folio/_assets/admin.css') {
                $match = $e;
                break;
            }
        }

        $this->assertNotNull($match, 'asset route should be registered');
        $this->assertSame('GET', $match->method);
        $this->assertSame('Preflow\\Folio\\Http\\AssetController@adminCss', $match->handler);
        // Prefix-configurability test relies on entries[0] staying the dashboard route.
        $this->assertSame('/folio', $entries[0]->pattern);
    }
```

- [ ] **Step 6: Run route test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Routing/FolioRoutesTest.php`
Expected: FAIL — `assertNotNull` fails (asset route not registered yet).

- [ ] **Step 7: Add the asset route entry**

In `packages/folio/src/Routing/FolioRoutes.php`, append the asset route after the loop that builds CRUD entries, before `return $entries;`:

```php
        // Package-owned admin stylesheet. Appended last: exact pattern, no
        // overlap with the CRUD routes, and keeps entries[0] = dashboard so the
        // prefix-configurability contract holds.
        $assetPattern = $prefix . '/_assets/admin.css';
        $ac = PatternCompiler::compile($assetPattern);
        $entries[] = new RouteEntry(
            pattern: $assetPattern,
            handler: 'Preflow\\Folio\\Http\\AssetController@adminCss',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $ac['paramNames'],
            regex: $ac['regex'],
            isCatchAll: $ac['isCatchAll'],
        );
```

- [ ] **Step 8: Run route test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Routing/FolioRoutesTest.php`
Expected: PASS (4 tests — the 3 existing plus the new one).

- [ ] **Step 9: Wire the controller binding and version global into the provider**

In `packages/folio/src/FolioServiceProvider.php`:

Add the import near the other `Http` imports:

```php
use Preflow\Folio\Http\AssetController;
```

In `register()`, after the `FrontendController` binding, add:

```php
        $container->bind(AssetController::class, fn (Container $c) => new AssetController(
            dirname(__DIR__) . '/assets/admin.css',
        ));
```

In `boot()`, inside the existing `if ($container->has(TemplateEngineInterface::class)) { ... }` block, after the two `addNamespace` calls, add:

```php
            // Single URL seam for the admin stylesheet. Content-hash version so
            // the immutable cache busts on edit. A future asset-publishing
            // system can change this one resolver without touching templates.
            $cssPath = dirname(__DIR__) . '/assets/admin.css';
            $version = is_file($cssPath) ? substr(hash_file('xxh3', $cssPath), 0, 12) : 'dev';
            $engine->addGlobal(
                'folio_admin_css_url',
                rtrim($this->prefix($app), '/') . '/_assets/admin.css?v=' . $version,
            );
```

- [ ] **Step 10: Write the failing end-to-end test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php` (inside the class):

```php
    public function test_admin_stylesheet_is_served(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(':root', (string) $res->getBody());
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }
```

- [ ] **Step 11: Run the integration test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — all methods including the new one. (This confirms the route is reachable through the real kernel and not shadowed by the frontend catch-all.)

- [ ] **Step 12: Commit**

```bash
git add packages/folio/src/Http/AssetController.php packages/folio/src/Routing/FolioRoutes.php packages/folio/src/FolioServiceProvider.php packages/folio/tests/Http/AssetControllerTest.php packages/folio/tests/Routing/FolioRoutesTest.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): serve admin.css via package-owned route with versioned URL seam"
```

---

### Task 3: App-shell layout with sidebar and theme toggle

**Files:**
- Modify: `packages/folio/templates/admin/_layout.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: `folio_admin_css_url` global (Task 2); template vars `prefix`, `types` (list of objects with `.key` and `.label`), optional `type`. Defines Twig blocks `title`, `page_title`, `actions`, `content` for child templates (Tasks 4–6).
- Produces: shell markup with `.folio-shell`, `.folio-sidebar`, `.folio-nav` (active item via `type`), `#folio-theme-toggle`, `.folio-topbar`, `.folio-content`; the stylesheet `<link>`; the no-flash + toggle inline scripts.

- [ ] **Step 1: Write the failing test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_admin_shell_renders_sidebar_stylesheet_and_toggle(): void
    {
        $body = (string) $this->get('/folio')->getBody();
        $this->assertStringContainsString('class="folio-shell"', $body);
        $this->assertStringContainsString('class="folio-sidebar"', $body);
        $this->assertStringContainsString('/folio/_assets/admin.css?v=', $body); // versioned link
        $this->assertStringContainsString('id="folio-theme-toggle"', $body);
        $this->assertStringContainsString("localStorage.getItem('folio-theme')", $body); // no-flash script
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_admin_shell_renders_sidebar_stylesheet_and_toggle`
Expected: FAIL — current `_layout.twig` has none of this markup.

- [ ] **Step 3: Replace the layout**

Overwrite `packages/folio/templates/admin/_layout.twig` with:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Folio{% endblock %}</title>
    {# No-flash theme: apply the stored choice before first paint. Plain inline
       script because action-mode responses bypass the asset collector. #}
    <script>
    (function () {
        try {
            var t = localStorage.getItem('folio-theme');
            if (t === 'light' || t === 'dark') {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="{{ folio_admin_css_url }}">
</head>
<body>
<div class="folio-shell">
    <aside class="folio-sidebar">
        <div class="folio-brand">Folio</div>
        <nav class="folio-nav">
            <a href="{{ prefix }}"{% if type is not defined %} class="active"{% endif %}>Dashboard</a>
            {% for t in types %}
                <a href="{{ prefix }}/{{ t.key }}"{% if type is defined and type == t.key %} class="active"{% endif %}>{{ t.label }}</a>
            {% endfor %}
        </nav>
        <div class="folio-sidebar-foot">
            <button type="button" id="folio-theme-toggle" class="folio-theme-toggle" aria-label="Toggle theme">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                <span>Theme</span>
            </button>
        </div>
    </aside>
    <div class="folio-main">
        <header class="folio-topbar">
            <h1>{% block page_title %}{% endblock %}</h1>
            <div class="folio-actions">{% block actions %}{% endblock %}</div>
        </header>
        <main class="folio-content">{% block content %}{% endblock %}</main>
    </div>
</div>
<script>
(function () {
    var btn = document.getElementById('folio-theme-toggle');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
        var cur = document.documentElement.getAttribute('data-theme');
        if (!cur) {
            cur = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        var next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        try { localStorage.setItem('folio-theme', next); } catch (e) {}
    });
})();
</script>
</body>
</html>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — all methods (the existing dashboard/form/frontend tests still pass because `types`, `prefix`, labels are unchanged).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/templates/admin/_layout.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): app-shell admin layout with sidebar nav and theme toggle"
```

---

### Task 4: Dashboard card grid with record counts

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`
- Modify: `packages/folio/templates/admin/dashboard.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: `TypeCatalog::all()` (objects with `.key`, `.label`); `DataManager::queryType($key)->get()->items()` for counts. The `_layout` blocks `page_title`, `actions`, `content` (Task 3).
- Produces: dashboard template var `cards` = `list<array{key:string,label:string,count:int}>`.

- [ ] **Step 1: Write the failing test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_dashboard_renders_type_card_with_count(): void
    {
        // Seed one record so the count is non-zero and deterministic.
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Seed', 'slug' => 'seed', 'body' => 'B', 'status' => 'published']));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio'))->getBody();
        $this->assertStringContainsString('folio-card-grid', $body);
        $this->assertStringContainsString('folio-card-label', $body);
        $this->assertStringContainsString('Pages', $body);
        $this->assertStringContainsString('1 record', $body);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_dashboard_renders_type_card_with_count`
Expected: FAIL — `folio-card-grid` / `1 record` not present (dashboard is still a `<ul>`).

- [ ] **Step 3: Build the cards in the controller**

In `packages/folio/src/Http/AdminController.php`, replace the body of `index()` (the `return $this->html(...)` for the dashboard) with:

```php
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        if (($override = $this->overrides->resolve('Content', 'Index')) !== null) {
            return $override->handle($request);
        }

        $cards = [];
        foreach ($this->catalog->all() as $listing) {
            $cards[] = [
                'key' => $listing->key,
                'label' => $listing->label,
                'count' => count($this->dm->queryType($listing->key)->get()->items()),
            ];
        }

        return $this->html($this->engine->render('@folio/admin/dashboard.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'cards' => $cards,
        ]));
    }
```

- [ ] **Step 4: Replace the dashboard template**

Overwrite `packages/folio/templates/admin/dashboard.twig` with:

```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — Dashboard{% endblock %}
{% block page_title %}Dashboard{% endblock %}
{% block content %}
    <div class="folio-card-grid">
        {% for card in cards %}
            <a class="folio-card" href="{{ prefix }}/{{ card.key }}">
                <span class="folio-card-label">{{ card.label }}</span>
                <span class="folio-card-count">{{ card.count }} record{{ card.count == 1 ? '' : 's' }}</span>
            </a>
        {% endfor %}
    </div>
{% endblock %}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — including the original `test_admin_dashboard_lists_discovered_type` (still finds the `Pages` label) and the new card test.

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/templates/admin/dashboard.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): dashboard card grid with per-type record counts"
```

---

### Task 5: List table with empty state and row actions

**Files:**
- Modify: `packages/folio/templates/admin/list.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: template vars `prefix`, `type`, `label`, `rows` (list of arrays with `id`, `title`, `slug`, `status`), `csrf` is NOT passed to list (delete form posts without a token in this test setup; CSRF is a no-op when no `config/auth.php`). The `_layout` blocks `page_title`, `actions`, `content`.
- Produces: a `.folio-table` with right-aligned Edit link + Delete form (`POST {prefix}/{type}/{id}/delete`), and a `.folio-empty` state; "New" moved into the topbar `actions` block.

- [ ] **Step 1: Write the failing test**

Add these two methods to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_list_shows_empty_state_when_no_records(): void
    {
        $body = (string) $this->get('/folio/page')->getBody();
        $this->assertStringContainsString('folio-empty', $body);
        $this->assertStringContainsString('No records yet', $body);
    }

    public function test_list_shows_row_with_edit_and_delete_actions(): void
    {
        $app = $this->app();
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Listed', 'slug' => 'listed', 'body' => 'B', 'status' => 'published']));

        $body = (string) $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio/page'))->getBody();
        $this->assertStringContainsString('folio-table', $body);
        $this->assertStringContainsString('Listed', $body);
        $this->assertStringContainsString('/edit', $body);
        $this->assertStringContainsString('/delete', $body);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_list_`
Expected: FAIL — `folio-empty` / `folio-table` / `/delete` not present yet.

- [ ] **Step 3: Replace the list template**

Overwrite `packages/folio/templates/admin/list.twig` with:

```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — {{ label }}{% endblock %}
{% block page_title %}{{ label }}{% endblock %}
{% block actions %}
    <a class="btn btn-primary" href="{{ prefix }}/{{ type }}/new">New</a>
{% endblock %}
{% block content %}
    {% if rows is empty %}
        <div class="folio-empty">
            <p>No records yet.</p>
            <a class="btn btn-primary" href="{{ prefix }}/{{ type }}/new">Create one</a>
        </div>
    {% else %}
        <table class="folio-table">
            <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th></th></tr></thead>
            <tbody>
            {% for row in rows %}
                <tr>
                    <td>{{ row.title }}</td>
                    <td>{{ row.slug }}</td>
                    <td>{{ row.status }}</td>
                    <td>
                        <div class="folio-row-actions">
                            <a class="btn btn-ghost" href="{{ prefix }}/{{ type }}/{{ row.id }}/edit">Edit</a>
                            <form method="post" action="{{ prefix }}/{{ type }}/{{ row.id }}/delete" onsubmit="return confirm('Delete this record?');">
                                <button type="submit" class="btn btn-danger">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            {% endfor %}
            </tbody>
        </table>
    {% endif %}
{% endblock %}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — all methods.

- [ ] **Step 5: Commit**

```bash
git add packages/folio/templates/admin/list.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): list table with empty state and edit/delete row actions"
```

---

### Task 6: Styled form with action bar and cancel

**Files:**
- Modify: `packages/folio/templates/admin/form.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: template vars `prefix`, `type`, `heading`, `action`, `csrf`, `fields`, `values`, `errors`; the form helper functions `form_begin`, `form.begin`, `form.field`, `form.submit`, `form_end` (unchanged); `_layout` blocks `page_title`, `actions`, `content`.
- Produces: form wrapped in `.folio-form`, Save in a `.folio-form-actions` bar with a Cancel link back to `{prefix}/{type}`.

- [ ] **Step 1: Write the failing test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_create_form_has_styled_actions_and_cancel(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        $this->assertStringContainsString('name="title"', $body);       // fields still render
        $this->assertStringContainsString('folio-form-actions', $body);  // action bar
        $this->assertStringContainsString('btn btn-primary', $body);     // emerald save button
        $this->assertStringContainsString('>Cancel<', $body);            // cancel link
        $this->assertStringContainsString('href="/folio/page"', $body);  // back to list
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_create_form_has_styled_actions_and_cancel`
Expected: FAIL — `folio-form-actions` / `Cancel` not present.

- [ ] **Step 3: Replace the form template**

Overwrite `packages/folio/templates/admin/form.twig` with:

```twig
{% extends "@folio/admin/_layout.twig" %}
{% block title %}Folio — {{ heading }}{% endblock %}
{% block page_title %}{{ heading }}{% endblock %}
{% block content %}
    <div class="folio-form">
        {% set form = form_begin({action: action, csrf_token: csrf}) %}
        {{ form.begin()|raw }}
        {% for field in fields %}
            {{ form.field(field.name, {type: field.input, value: values[field.name]|default(''), errors: errors[field.name]|default([])})|raw }}
        {% endfor %}
        <div class="folio-form-actions">
            {{ form.submit('Save', {attrs: {class: 'btn btn-primary'}})|raw }}
            <a class="btn btn-secondary" href="{{ prefix }}/{{ type }}">Cancel</a>
        </div>
        {{ form_end()|raw }}
    </div>
{% endblock %}
```

`FormBuilder::submit($label, $options)` merges `$options['attrs']` onto the
`<button type="submit">`, so passing `{attrs: {class: 'btn btn-primary'}}` gives
the Save button the emerald styling directly (verified in
`packages/form/src/FormBuilder.php:185-201`).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — all methods (including the original `test_create_form_renders_under_strict_twig`).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/templates/admin/form.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): styled admin form with action bar and cancel"
```

---

### Task 7: Standalone login template

**Files:**
- Create: `packages/folio/templates/admin/login.twig`
- Modify: `packages/folio/tests/TemplatesExistTest.php`
- Test: `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Consumes: `folio_admin_css_url` global (Task 2); optional `login_action` and `login_error` vars (both guarded with `|default`). Standalone — does NOT extend `_layout` (no sidebar on the auth screen).
- Produces: a self-contained HTML document with `.folio-auth` / `.folio-auth-card`, an email + password form, and an emerald submit. Wiring to a real auth route is out of scope.

- [ ] **Step 1: Write the failing render test**

Add this method to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_login_template_renders_standalone_card(): void
    {
        $app = $this->app();
        $engine = $app->container()->get(\Preflow\View\TemplateEngineInterface::class);
        $html = $engine->render('@folio/admin/login.twig', []);

        $this->assertStringContainsString('folio-auth-card', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringNotContainsString('folio-sidebar', $html); // not the app shell
    }
```

Note: `Application::container()` is the container accessor (confirmed at `packages/core/src/Application.php:72`).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_login_template_renders_standalone_card`
Expected: FAIL — template `@folio/admin/login.twig` does not exist (Twig loader error).

- [ ] **Step 3: Create the login template**

Create `packages/folio/templates/admin/login.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Folio — Sign in</title>
    <script>
    (function () {
        try {
            var t = localStorage.getItem('folio-theme');
            if (t === 'light' || t === 'dark') {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="{{ folio_admin_css_url }}">
</head>
<body>
<div class="folio-auth">
    <form class="folio-auth-card" method="post" action="{{ login_action|default('') }}">
        <div class="folio-brand">Folio</div>
        {% if login_error|default('') %}
            <div class="folio-alert folio-alert-error">{{ login_error }}</div>
        {% endif %}
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" name="email" id="email" autocomplete="username" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" name="password" id="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary">Sign in</button>
    </form>
</div>
</body>
</html>
```

- [ ] **Step 4: Run render test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_login_template_renders_standalone_card`
Expected: PASS.

- [ ] **Step 5: Add login to the templates-exist guard**

In `packages/folio/tests/TemplatesExistTest.php`, add `'/admin/login.twig',` to the array of expected templates (after `'/admin/form.twig',`).

- [ ] **Step 6: Run the templates-exist test**

Run: `vendor/bin/phpunit packages/folio/tests/TemplatesExistTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/folio/templates/admin/login.twig packages/folio/tests/TemplatesExistTest.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): standalone login screen template"
```

---

### Task 8: Full-suite regression and README note

**Files:**
- Modify: `packages/folio/README.md` (styling/CSP note)
- Test: entire Folio package suite

**Interfaces:**
- Consumes: everything from Tasks 1–7.
- Produces: a documented styling section; a green suite.

- [ ] **Step 1: Run the whole Folio suite**

Run: `vendor/bin/phpunit packages/folio/tests`
Expected: PASS — all tests green, no warnings about risky/incomplete tests beyond any pre-existing ones.

- [ ] **Step 2: Run the full repo suite to catch cross-package breakage**

Run: `vendor/bin/phpunit`
Expected: PASS — the 770+ existing tests plus the new Folio tests. If anything outside Folio fails, it is unrelated to this change; investigate before continuing.

- [ ] **Step 3: Add a styling note to the Folio README**

In `packages/folio/README.md`, add a section (place it after the existing admin/templates section; match the file's existing heading style):

```markdown
## Admin styling

The admin ships a single stylesheet served by Folio itself at
`{prefix}/_assets/admin.css` (no build step, no asset publishing required). The
`<link>` URL is content-hash versioned via the `folio_admin_css_url` Twig
global, so it caches immutably and busts on change.

Dark mode is built in: the stylesheet defines warm-neutral tokens with an
emerald accent and a `[data-theme="dark"]` override. The default follows the
operating system (`prefers-color-scheme`); a sidebar toggle lets the user
override it, persisted in `localStorage` with a no-flash inline script.

Every template is overridable via the `@folio` namespace (drop replacements in
`resources/folio/`). Because the admin renders through action-mode controllers,
the stylesheet link and the small theme scripts are plain markup in
`_layout.twig` rather than going through the asset collector. If you serve the
admin under a strict `Content-Security-Policy`, allow `style-src 'self'` for the
stylesheet and either nonce or allow the two inline theme scripts (or override
`_layout.twig` to suit your policy).
```

- [ ] **Step 4: Commit**

```bash
git add packages/folio/README.md
git commit -m "docs(folio): document admin styling, dark mode, and CSP notes"
```

---

## Self-Review

**Spec coverage:**
- Visual language / warm tokens / emerald accent → Task 1 (stylesheet).
- Design tokens + dark mode (`:root` + `[data-theme="dark"]` + `prefers-color-scheme`) → Task 1, asserted by `AdminCssTest`.
- Theme switching (no-flash + toggle + persistence) → Task 3.
- App shell (sidebar, topbar, content, active nav, responsive) → Task 1 (CSS) + Task 3 (markup).
- Dashboard card grid + counts → Task 4.
- List table + empty state + row actions → Task 5.
- Form styling over existing `preflow/form` hooks + action bar + cancel → Task 1 (CSS) + Task 6.
- Login screen → Task 7.
- Buttons + alerts → Task 1.
- Delivery: real `.css` file, package-owned route, single URL seam, content-hash version, no `asset_url()` overload → Task 2.
- CSP / nonce handling (no default CSP; documented for strict-CSP hosts; theme scripts plain markup because action-mode bypasses the collector) → Tasks 3 & 8.
- Tests: asset route content-type + cache + version; render tests for shell/list/form/login; static dark-mode check → Tasks 1–7.
- Overridability via `@folio` → preserved (templates unchanged in namespace), documented in Task 8.

**Out of scope (per spec), intentionally not implemented:** auth logic, media manager, WYSIWYG, i18n, build tooling.

**Placeholder scan:** No `TBD`/`TODO`. Remaining `Note:` callouts (Task 6 submit-attrs, Task 7 container accessor) cite verified file:line references, not deferrals.

**Type consistency:** `folio_admin_css_url` global name identical in Tasks 2, 3, 7. `cards` array shape (`key`/`label`/`count`) defined in Task 4 controller and consumed by Task 4 template. `AssetController::adminCss` handler string identical in route entry (Task 2 Step 7) and route test (Task 2 Step 5). Route pattern `/_assets/admin.css` identical across controller test, route, integration test.
