# Phase 10: Skeleton Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `composer create-project preflow/skeleton myapp` a zero-friction experience with post-install automation, session-persisted counter, LocaleSwitcher component, Apache config, and polished README.

**Architecture:** Mostly skeleton modifications (config, templates, components, README) plus one new DevTools command (`key:generate`). No new packages — all changes are in `preflow/skeleton` and `preflow/devtools`.

**Tech Stack:** PHP 8.4+, Twig, HTMX, PHPUnit 11

**Design spec:** `docs/superpowers/specs/2026-04-12-phase10-skeleton-design.md`

---

## File Structure

### New files

```
packages/devtools/src/Command/KeyGenerateCommand.php    — generates APP_KEY in .env
packages/devtools/tests/Command/KeyGenerateCommandTest.php

packages/skeleton/app/Components/LocaleSwitcher/
├── LocaleSwitcher.php     — reads Translator locale + RequestContext path
└── LocaleSwitcher.twig    — button group with locale links

packages/skeleton/public/.htaccess   — Apache mod_rewrite to index.php
```

### Modified files

```
packages/devtools/src/Console.php                 — register KeyGenerateCommand
packages/skeleton/composer.json                   — add post-create-project-cmd scripts
packages/skeleton/app/Components/ExampleCard/ExampleCard.php  — inject SessionInterface, persist counter
packages/skeleton/app/pages/_layout.twig          — add LocaleSwitcher
packages/skeleton/app/pages/_error.twig           — dynamic status code/message
packages/skeleton/app/pages/index.twig            — update demo description text
packages/skeleton/config/middleware.php            — remove misleading comment
packages/skeleton/README.md                        — full rewrite
```

---

### Task 1: KeyGenerateCommand

**Files:**
- Create: `packages/devtools/src/Command/KeyGenerateCommand.php`
- Create: `packages/devtools/tests/Command/KeyGenerateCommandTest.php`
- Modify: `packages/devtools/src/Console.php`

- [ ] **Step 1: Write KeyGenerateCommand test**

Create `packages/devtools/tests/Command/KeyGenerateCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\KeyGenerateCommand;

final class KeyGenerateCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_keygen_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir);
    }

    public function test_generates_key_in_env_file(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_NAME=Test\nAPP_KEY=change-me\nAPP_DEBUG=1\n");

        $cmd = new KeyGenerateCommand();
        $result = $cmd->executeInDir($this->tmpDir);

        $this->assertSame(0, $result);

        $env = file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString('APP_NAME=Test', $env);
        $this->assertStringContainsString('APP_DEBUG=1', $env);
        $this->assertStringNotContainsString('change-me', $env);
        $this->assertMatchesRegularExpression('/APP_KEY=[a-f0-9]{32}/', $env);
    }

    public function test_fails_when_env_missing(): void
    {
        $cmd = new KeyGenerateCommand();
        $result = $cmd->executeInDir($this->tmpDir);

        $this->assertSame(1, $result);
    }

    public function test_adds_key_when_empty(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=\n");

        $cmd = new KeyGenerateCommand();
        $result = $cmd->executeInDir($this->tmpDir);

        $this->assertSame(0, $result);

        $env = file_get_contents($this->tmpDir . '/.env');
        $this->assertMatchesRegularExpression('/APP_KEY=[a-f0-9]{32}/', $env);
    }
}
```

- [ ] **Step 2: Run test — expect failure**

Run: `./vendor/bin/phpunit packages/devtools/tests/Command/KeyGenerateCommandTest.php`
Expected: FAIL — `KeyGenerateCommand` class not found.

- [ ] **Step 3: Implement KeyGenerateCommand**

Create `packages/devtools/src/Command/KeyGenerateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\DevTools\Command;

final class KeyGenerateCommand implements CommandInterface
{
    public function getName(): string { return 'key:generate'; }
    public function getDescription(): string { return 'Generate a random APP_KEY in .env'; }

    public function execute(array $args): int
    {
        return $this->executeInDir(getcwd());
    }

    public function executeInDir(string $dir): int
    {
        $envPath = $dir . '/.env';

        if (!file_exists($envPath)) {
            fwrite(STDERR, "Error: .env file not found. Copy .env.example first.\n");
            return 1;
        }

        $key = bin2hex(random_bytes(16));
        $contents = file_get_contents($envPath);
        $contents = preg_replace('/^APP_KEY=.*$/m', 'APP_KEY=' . $key, $contents);
        file_put_contents($envPath, $contents);

        echo "Application key set: {$key}\n";
        return 0;
    }
}
```

- [ ] **Step 4: Run test — expect pass**

Run: `./vendor/bin/phpunit packages/devtools/tests/Command/KeyGenerateCommandTest.php`
Expected: 3 tests, all PASS.

- [ ] **Step 5: Register command in Console**

In `packages/devtools/src/Console.php`, add to the constructor after the existing `register()` calls:

```php
$this->register(new Command\KeyGenerateCommand());
```

- [ ] **Step 6: Commit**

```bash
git add packages/devtools/src/Command/KeyGenerateCommand.php packages/devtools/tests/Command/KeyGenerateCommandTest.php packages/devtools/src/Console.php
git commit -m "feat(devtools): add key:generate command"
```

---

### Task 2: Composer post-install scripts

**Files:**
- Modify: `packages/skeleton/composer.json`

- [ ] **Step 1: Add scripts section**

In `packages/skeleton/composer.json`, add the `scripts` key at the top level:

```json
"scripts": {
    "post-create-project-cmd": [
        "@php -r \"copy('.env.example', '.env');\"",
        "@php preflow key:generate",
        "@php preflow migrate",
        "@php preflow db:seed"
    ]
}
```

Note: The seed command is `db:seed` (as defined in `SeedCommand::getName()`).

- [ ] **Step 2: Commit**

```bash
git add packages/skeleton/composer.json
git commit -m "feat(skeleton): add post-create-project automation scripts"
```

---

### Task 3: Session-persisted counter (ExampleCard)

**Files:**
- Modify: `packages/skeleton/app/Components/ExampleCard/ExampleCard.php`

- [ ] **Step 1: Modify ExampleCard to use session**

Replace the entire `packages/skeleton/app/Components/ExampleCard/ExampleCard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Components\ExampleCard;

use Preflow\Components\Component;
use Preflow\Core\Http\Session\SessionInterface;

final class ExampleCard extends Component
{
    private const SESSION_KEY = 'example_counter';

    public string $title = '';
    public string $message = '';
    public int $count = 0;

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Welcome to Preflow';
        $this->message = $this->props['message'] ?? 'Your first component.';
        $this->count = (int) $this->session->get(self::SESSION_KEY, 0);
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params = []): void
    {
        $this->count = (int) $this->session->get(self::SESSION_KEY, 0) + 1;
        $this->session->set(self::SESSION_KEY, $this->count);
        $this->props['count'] = $this->count;
    }
}
```

Key changes:
- Constructor injects `SessionInterface` (autowired from container)
- `resolveState()` reads count from session instead of props
- `actionIncrement()` reads, increments, and writes back to session
- Removed the `count` prop dependency — session is the source of truth

- [ ] **Step 2: Update ExampleCard.twig counter description**

In `packages/skeleton/app/Components/ExampleCard/ExampleCard.twig`, the template stays the same. The count variable is still a public property on the component, populated from session. No template changes needed.

- [ ] **Step 3: Run existing tests**

Run: `./vendor/bin/phpunit packages/skeleton/tests/`
Expected: Existing tests pass (ExampleTest, NavigationActiveStateTest). If ExampleCard tests exist that reference the old prop-based count, update them.

- [ ] **Step 4: Commit**

```bash
git add packages/skeleton/app/Components/ExampleCard/ExampleCard.php
git commit -m "feat(skeleton): persist ExampleCard counter in session"
```

---

### Task 4: LocaleSwitcher component

**Files:**
- Create: `packages/skeleton/app/Components/LocaleSwitcher/LocaleSwitcher.php`
- Create: `packages/skeleton/app/Components/LocaleSwitcher/LocaleSwitcher.twig`

- [ ] **Step 1: Create LocaleSwitcher PHP class**

Create `packages/skeleton/app/Components/LocaleSwitcher/LocaleSwitcher.php`:

```php
<?php

declare(strict_types=1);

namespace App\Components\LocaleSwitcher;

use Preflow\Components\Component;
use Preflow\Core\Http\RequestContext;
use Preflow\I18n\Translator;

final class LocaleSwitcher extends Component
{
    /** @var array<int, array{code: string, label: string, url: string, active: bool}> */
    public array $locales = [];

    public function __construct(
        private readonly Translator $translator,
        private readonly RequestContext $requestContext,
    ) {}

    public function resolveState(): void
    {
        $currentLocale = $this->translator->getLocale();
        $currentPath = $this->requestContext->path;

        // Ensure path starts with /
        if ($currentPath === '' || $currentPath === '/') {
            $currentPath = '/';
        }

        foreach ($this->props['locales'] ?? [] as $code) {
            $url = '/' . $code . ($currentPath === '/' ? '' : $currentPath);

            $this->locales[] = [
                'code' => $code,
                'label' => strtoupper($code),
                'url' => $url,
                'active' => $code === $currentLocale,
            ];
        }
    }
}
```

- [ ] **Step 2: Create LocaleSwitcher template**

Create `packages/skeleton/app/Components/LocaleSwitcher/LocaleSwitcher.twig`:

```twig
<div class="locale-switcher">
    {% for locale in locales %}
        {% if locale.active %}
            <span class="locale-btn locale-btn--active">{{ locale.label }}</span>
        {% else %}
            <a href="{{ locale.url }}" class="locale-btn">{{ locale.label }}</a>
        {% endif %}
    {% endfor %}
</div>

{% apply css %}
.locale-switcher {
    display: flex;
    gap: 0.25rem;
}

.locale-btn {
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
    color: #555;
    background: transparent;
    transition: background 0.15s, color 0.15s;
}

.locale-btn:hover {
    background: #e9ecef;
    color: #333;
}

.locale-btn--active {
    background: #0066ff;
    color: white;
}
{% endapply %}
```

- [ ] **Step 3: Commit**

```bash
git add packages/skeleton/app/Components/LocaleSwitcher/
git commit -m "feat(skeleton): add LocaleSwitcher component"
```

---

### Task 5: .htaccess + config cleanup + error page

**Files:**
- Create: `packages/skeleton/public/.htaccess`
- Modify: `packages/skeleton/config/middleware.php`
- Modify: `packages/skeleton/app/pages/_error.twig`

- [ ] **Step 1: Create .htaccess**

Create `packages/skeleton/public/.htaccess`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
```

- [ ] **Step 2: Clean up middleware.php**

Replace `packages/skeleton/config/middleware.php` with:

```php
<?php

return [
    // Add your global middleware here.
    // Framework middleware (session, CSRF, i18n) is auto-discovered — no need to register it.
];
```

- [ ] **Step 3: Fix _error.twig to be dynamic**

Replace `packages/skeleton/app/pages/_error.twig`:

```twig
{% extends "_layout.twig" %}

{% block title %}Error {{ status|default(404) }}{% endblock %}

{% block content %}
{% apply css %}
.error-page {
    text-align: center;
    padding: 4rem 0;
}

.error-code {
    font-size: 6rem;
    font-weight: 700;
    color: #dee2e6;
    line-height: 1;
}

.error-message {
    color: #666;
    font-size: 1.125rem;
    margin-top: 0.5rem;
}

.error-link {
    display: inline-block;
    margin-top: 1.5rem;
    color: #0066ff;
    text-decoration: none;
}

.error-link:hover {
    text-decoration: underline;
}
{% endapply %}

<div class="error-page">
    <h1 class="error-code">{{ status|default(404) }}</h1>
    <p class="error-message">{{ message|default('Page not found') }}</p>
    <a href="/" class="error-link">Go home</a>
</div>
{% endblock %}
```

- [ ] **Step 4: Commit**

```bash
git add packages/skeleton/public/.htaccess packages/skeleton/config/middleware.php packages/skeleton/app/pages/_error.twig
git commit -m "feat(skeleton): add .htaccess, clean up middleware config, fix error page"
```

---

### Task 6: Layout integration + homepage update

**Files:**
- Modify: `packages/skeleton/app/pages/_layout.twig`
- Modify: `packages/skeleton/app/pages/index.twig`

- [ ] **Step 1: Add LocaleSwitcher to layout**

Replace `packages/skeleton/app/pages/_layout.twig`:

```twig
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Preflow App{% endblock %}</title>
</head>
<body>
    <header class="main-header">
        {{ component('Navigation', {
            brand: 'Preflow',
            items: [
                { path: '/', label: 'app.nav.home' },
                { path: '/blog', label: 'app.nav.blog' },
                { path: '/about', label: 'app.nav.about' },
            ]
        }) }}
        {{ component('LocaleSwitcher', { locales: ['en', 'de'] }) }}
    </header>

    <main class="main-content">
        {% block content %}{% endblock %}
    </main>

    <footer class="main-footer">
        {{ t('app.footer') }}
    </footer>
</body>
</html>

{% apply css %}
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: system-ui, -apple-system, sans-serif;
    color: #333;
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.main-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-right: 1rem;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.main-header nav {
    border-bottom: none;
}

.main-content {
    flex: 1;
    padding: 2rem;
    max-width: 800px;
    width: 100%;
    margin: 0 auto;
}

.main-footer {
    padding: 1.5rem 2rem;
    text-align: center;
    color: #999;
    font-size: 0.875rem;
    border-top: 1px solid #eee;
}
{% endapply %}
```

Key changes:
- Wrapped Navigation + LocaleSwitcher in a `<header class="main-header">` flexbox
- LocaleSwitcher positioned to the right via `justify-content: space-between`
- Removed `border-bottom` from nav (header handles it now)

- [ ] **Step 2: Update index.twig counter description**

In `packages/skeleton/app/pages/index.twig`, update the ExampleCard component call to mention session persistence:

Find and replace the ExampleCard component call:

```twig
{{ component('ExampleCard', {
    title: 'Hello, Preflow',
    message: 'This component has co-located CSS, JS, and a session-persisted HTMX counter. Try incrementing and refreshing the page.'
}) }}
```

- [ ] **Step 3: Commit**

```bash
git add packages/skeleton/app/pages/_layout.twig packages/skeleton/app/pages/index.twig
git commit -m "feat(skeleton): add LocaleSwitcher to layout, update homepage copy"
```

---

### Task 7: README overhaul

**Files:**
- Modify: `packages/skeleton/README.md`

- [ ] **Step 1: Rewrite README**

Replace `packages/skeleton/README.md`:

```markdown
# Preflow Skeleton

Starter template for [Preflow](https://github.com/getpreflow/preflow) applications.

## Quick Start

```bash
composer create-project preflow/skeleton myapp
cd myapp
php preflow serve
```

Open [http://localhost:8080](http://localhost:8080). That's it — the installer handles `.env`, database, and demo content automatically.

## Project Structure

```
app/
├── Components/    Reusable UI components (PHP + template + CSS/JS)
├── Controllers/   API and form controllers (#[Route] attributes)
├── Models/        Data models (#[Entity] attributes)
├── Providers/     Service providers
├── Seeds/         Demo data seeders
└── pages/         File-based routes (Twig templates)
config/            Framework configuration
lang/              Translation files (en/, de/)
migrations/        Database schema
public/            Web root (index.php, .htaccess)
storage/           SQLite database, cache, logs
tests/             PHPUnit tests
```

## What's Included

### Routing

File-based routes in `app/pages/` map to URLs by directory structure. Dynamic segments use brackets: `blog/[slug].twig` matches `/blog/hello-world`.

Controllers use PHP attributes:

```php
#[Route('/api')]
final class HealthController
{
    #[Get('/health')]
    public function health(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'],
            json_encode(['status' => 'ok']));
    }
}
```

### Components

A component is a PHP class + Twig template + inline CSS/JS in one directory. Drop it in `app/Components/`, it auto-discovers.

```php
final class ExampleCard extends Component
{
    public string $title = '';
    public int $count = 0;

    public function __construct(private readonly SessionInterface $session) {}

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Hello';
        $this->count = (int) $this->session->get('example_counter', 0);
    }

    public function actions(): array { return ['increment']; }

    public function actionIncrement(array $params = []): void
    {
        $this->count = (int) $this->session->get('example_counter', 0) + 1;
        $this->session->set('example_counter', $this->count);
    }
}
```

Use in templates: `{{ component('ExampleCard', { title: 'Hello' }) }}`

### Authentication

Login, registration, and logout are included. Protect routes with middleware:

```php
#[Route('/dashboard')]
#[Middleware(AuthMiddleware::class)]
final class DashboardController { /* ... */ }
```

Templates can check auth status:

```twig
{% if auth_check() %}
    Welcome, {{ auth_user().email }}
{% endif %}
```

### Internationalization

Translations live in `lang/{locale}/{group}.php`. Switch languages with the locale switcher in the header, or visit `/de/...` for German.

```twig
{{ t('blog.title') }}
{{ t('blog.published', { date: '2026-01-01' }) }}
{{ t('blog.post_count', {}, 5) }}
```

### Data Layer

Models use PHP attributes for storage mapping:

```php
#[Entity(table: 'posts', storage: 'default')]
final class Post extends Model
{
    #[Id] public string $uuid = '';
    #[Field(searchable: true)] public string $title = '';
    #[Field] public string $status = 'draft';
}
```

Query with the DataManager:

```php
$posts = $dm->query(Post::class)
    ->where('status', 'published')
    ->orderBy('uuid', SortDirection::Desc)
    ->get();
```

### HTMX

Components can define actions that handle HTMX requests. The ExampleCard counter persists in the session across page reloads — no JavaScript needed.

## Configuration

| File | Purpose |
|------|---------|
| `config/app.php` | App name, debug level, timezone, locale, template engine |
| `config/auth.php` | Guards, user providers, session settings |
| `config/data.php` | Storage drivers (SQLite, JSON, MySQL) |
| `config/i18n.php` | Available locales, fallback, URL strategy |
| `config/providers.php` | Service provider registration |
| `.env` | Environment-specific overrides |

## CLI Commands

```bash
php preflow serve           # Start dev server (localhost:8080)
php preflow migrate         # Run pending migrations
php preflow db:seed         # Seed demo data
php preflow key:generate    # Generate APP_KEY
php preflow routes:list     # List all routes
php preflow cache:clear     # Clear cache
```

## Web Server

**Development:** `php preflow serve` — no configuration needed.

**Apache:** Point your document root to the `public/` directory. The included `.htaccess` handles URL rewriting. Ensure `mod_rewrite` is enabled.

**Nginx:**

```nginx
server {
    listen 80;
    server_name myapp.test;
    root /path/to/myapp/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Testing

```bash
./vendor/bin/phpunit
```

Tests live in `tests/`. The skeleton includes example tests for components and routing.
```

- [ ] **Step 2: Commit**

```bash
git add packages/skeleton/README.md
git commit -m "docs(skeleton): rewrite README for zero-friction quickstart"
```

---

### Task 8: Tests + final verification

**Files:**
- Create: `packages/skeleton/tests/LocaleSwitcherTest.php`
- Modify: existing tests if needed

- [ ] **Step 1: Write LocaleSwitcher test**

Create `packages/skeleton/tests/LocaleSwitcherTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\Components\LocaleSwitcher\LocaleSwitcher;
use Preflow\Core\Http\RequestContext;
use Preflow\I18n\Translator;

final class LocaleSwitcherTest extends TestCase
{
    private function createComponent(string $locale, string $path, array $locales = ['en', 'de']): LocaleSwitcher
    {
        $langDir = sys_get_temp_dir() . '/preflow_ls_test_' . uniqid();
        mkdir($langDir . '/en', 0755, true);
        mkdir($langDir . '/de', 0755, true);
        file_put_contents($langDir . '/en/app.php', '<?php return [];');
        file_put_contents($langDir . '/de/app.php', '<?php return [];');

        $translator = new Translator($langDir, $locale, 'en');
        $requestContext = new RequestContext(path: $path, method: 'GET');

        $component = new LocaleSwitcher($translator, $requestContext);
        $component->setProps(['locales' => $locales]);
        $component->resolveState();

        // Cleanup
        foreach (glob($langDir . '/{en,de}/app.php', GLOB_BRACE) as $f) { unlink($f); }
        rmdir($langDir . '/en');
        rmdir($langDir . '/de');
        rmdir($langDir);

        return $component;
    }

    public function test_renders_all_locales(): void
    {
        $component = $this->createComponent('en', '/blog');
        $this->assertCount(2, $component->locales);
        $this->assertSame('EN', $component->locales[0]['label']);
        $this->assertSame('DE', $component->locales[1]['label']);
    }

    public function test_marks_current_locale_active(): void
    {
        $component = $this->createComponent('de', '/blog');
        $this->assertFalse($component->locales[0]['active']); // EN
        $this->assertTrue($component->locales[1]['active']);   // DE
    }

    public function test_generates_correct_urls(): void
    {
        $component = $this->createComponent('en', '/blog');
        $this->assertSame('/en/blog', $component->locales[0]['url']);
        $this->assertSame('/de/blog', $component->locales[1]['url']);
    }

    public function test_handles_root_path(): void
    {
        $component = $this->createComponent('en', '/');
        $this->assertSame('/en', $component->locales[0]['url']);
        $this->assertSame('/de', $component->locales[1]['url']);
    }
}
```

- [ ] **Step 2: Run test — expect pass**

Run: `./vendor/bin/phpunit packages/skeleton/tests/LocaleSwitcherTest.php`
Expected: 4 tests, all PASS.

- [ ] **Step 3: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests pass. No regressions.

- [ ] **Step 4: Commit**

```bash
git add packages/skeleton/tests/LocaleSwitcherTest.php
git commit -m "test(skeleton): add LocaleSwitcher component tests"
```

---

## Implementation Notes

### Navigation CSS conflict

When wrapping Navigation and LocaleSwitcher in a `<header>`, the Navigation component's own `border-bottom` on `.nav-inner` may conflict with the header's border. The layout's CSS sets `border-bottom: none` on `nav` to prevent a double border. If the Navigation component renders differently than expected, adjust the `.main-header nav` selector.

### ExampleCard session dependency

The ExampleCard now requires `SessionInterface` in its constructor. If sessions are not configured (no `config/auth.php` with session settings), the container will fail to autowire the component. Since the skeleton always ships `config/auth.php`, this is fine for the skeleton. But if someone removes auth config, the ExampleCard breaks. This is acceptable — the skeleton is a demo, not a library.

### Composer scripts and Packagist

The `post-create-project-cmd` scripts run after `composer create-project` completes. They assume `preflow`, `vendor/`, and `.env.example` exist in the project root. The `@php` prefix ensures the correct PHP binary is used. These scripts do NOT run on regular `composer install` — only on project creation.

### LocaleSwitcher URL generation

The LocaleSwitcher generates URLs as `/{locale}{path}` where `path` is the current request path with the locale prefix already stripped by `LocaleMiddleware`. This means:
- Visiting `/de/blog` → RequestContext path is `/blog` → EN link: `/en/blog`, DE link: `/de/blog`
- Visiting `/blog` (default locale) → RequestContext path is `/blog` → links work the same

This depends on the `prefix` URL strategy being active in `config/i18n.php`.
