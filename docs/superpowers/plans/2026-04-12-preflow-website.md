# Preflow Website Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a marketing and documentation website for Preflow, built with Preflow itself, deployed to shared hosting via Deployer.

**Architecture:** Fork the skeleton app into an independent `preflow-website` repo. Strip blog demo, reshape for a marketing landing page + markdown-based documentation system. Dark/light theme via CSS custom properties. Docs authored as `.md` files, parsed by `league/commonmark` with `tempest/highlight` syntax highlighting.

**Tech Stack:** Preflow (core, routing, view, twig, components, htmx), league/commonmark, tempest/highlight, deployer/deployer

**Design Spec:** `docs/superpowers/specs/2026-04-12-preflow-website-design.md`

---

## File Structure

```
preflow-website/
├── app/
│   ├── Components/
│   │   ├── Navigation/Navigation.php, Navigation.twig
│   │   ├── ThemeToggle/ThemeToggle.php, ThemeToggle.twig
│   │   ├── Hero/Hero.php, Hero.twig
│   │   ├── FeatureCard/FeatureCard.php, FeatureCard.twig
│   │   ├── FeatureGrid/FeatureGrid.php, FeatureGrid.twig
│   │   ├── CodeExample/CodeExample.php, CodeExample.twig
│   │   ├── PackageCard/PackageCard.php, PackageCard.twig
│   │   ├── QuickStart/QuickStart.php, QuickStart.twig
│   │   ├── ArchitectureDiagram/ArchitectureDiagram.php, ArchitectureDiagram.twig
│   │   ├── DocsSidebar/DocsSidebar.php, DocsSidebar.twig
│   │   ├── DocsPage/DocsPage.php, DocsPage.twig
│   │   ├── DocsSearch/DocsSearch.php, DocsSearch.twig
│   │   ├── TableOfContents/TableOfContents.php, TableOfContents.twig
│   │   └── Footer/Footer.php, Footer.twig
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   └── DocsServiceProvider.php
│   ├── Markdown/
│   │   ├── MarkdownParser.php
│   │   ├── FrontmatterExtractor.php
│   │   └── SyntaxHighlightExtension.php
│   └── pages/
│       ├── _layout.twig
│       ├── index.twig
│       └── docs/
│           └── [...path].twig
├── config/
│   ├── app.php
│   ├── middleware.php
│   └── providers.php
├── docs/
│   ├── manifest.php
│   ├── getting-started/
│   │   ├── installation.md
│   │   ├── configuration.md
│   │   └── directory-structure.md
│   ├── guides/
│   │   ├── routing.md
│   │   ├── components.md
│   │   ├── data.md
│   │   ├── authentication.md
│   │   ├── internationalization.md
│   │   └── testing.md
│   └── packages/
│       ├── core.md, routing.md, view.md, components.md
│       ├── twig.md, blade.md, data.md, htmx.md
│       ├── i18n.md, auth.md, devtools.md, testing.md
│       └── skeleton.md
├── public/
│   ├── index.php
│   └── .htaccess
├── storage/
│   ├── cache/docs/
│   └── logs/
├── tests/
│   ├── Markdown/
│   │   ├── MarkdownParserTest.php
│   │   ├── FrontmatterExtractorTest.php
│   │   └── SyntaxHighlightExtensionTest.php
│   └── Components/
│       ├── DocsPageTest.php
│       ├── DocsSidebarTest.php
│       └── DocsSearchTest.php
├── deploy.php
├── composer.json
└── .env.example
```

---

### Task 1: Project Bootstrap

**Files:**
- Create: `preflow-website/composer.json`
- Create: `preflow-website/public/index.php`
- Create: `preflow-website/public/.htaccess`
- Create: `preflow-website/.env.example`
- Create: `preflow-website/.env`
- Create: `preflow-website/.gitignore`
- Create: `preflow-website/config/app.php`
- Create: `preflow-website/config/middleware.php`
- Create: `preflow-website/config/providers.php`
- Create: `preflow-website/app/Providers/AppServiceProvider.php`

- [ ] **Step 1: Create project directory and initialize git**

```bash
mkdir -p ~/Sites/gbits/preflow-website
cd ~/Sites/gbits/preflow-website
git init
```

- [ ] **Step 2: Create composer.json**

```json
{
    "name": "preflow/website",
    "description": "Preflow framework website and documentation",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "preflow/core": "^0.10",
        "preflow/routing": "^0.10",
        "preflow/view": "^0.10",
        "preflow/twig": "^0.10",
        "preflow/components": "^0.10",
        "preflow/htmx": "^0.10",
        "league/commonmark": "^2.6",
        "tempest/highlight": "^3.0",
        "nyholm/psr7": "^1.8",
        "nyholm/psr7-server": "^1.1"
    },
    "require-dev": {
        "preflow/devtools": "^0.10",
        "preflow/testing": "^0.10",
        "deployer/deployer": "^7.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Create public/index.php**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = Preflow\Core\Application::create(__DIR__ . '/..');
$app->boot();
$app->run();
```

- [ ] **Step 4: Create public/.htaccess**

Copy from the skeleton's `.htaccess`. Standard Apache rewrite rules directing everything to `index.php`.

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

- [ ] **Step 5: Create .env.example and .env**

```
APP_NAME="Preflow"
APP_DEBUG=1
APP_KEY=change-this-to-a-random-32-char-string!
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_ENGINE=twig
```

Copy `.env.example` to `.env`.

- [ ] **Step 6: Create config files**

`config/app.php`:
```php
<?php

return [
    'name' => getenv('APP_NAME') ?: 'Preflow',
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
    'engine' => getenv('APP_ENGINE') ?: 'twig',
];
```

`config/middleware.php`:
```php
<?php

return [];
```

`config/providers.php`:
```php
<?php

return [
    \App\Providers\AppServiceProvider::class,
    \App\Providers\DocsServiceProvider::class,
];
```

- [ ] **Step 7: Create AppServiceProvider**

`app/Providers/AppServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Preflow\Core\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }
}
```

- [ ] **Step 8: Create .gitignore**

```
/vendor/
/.env
/storage/cache/
/storage/logs/
.superpowers/
```

- [ ] **Step 9: Create directory structure**

```bash
mkdir -p app/{Components,Controllers,Providers,Markdown,pages/docs}
mkdir -p config
mkdir -p docs/{getting-started,guides,packages}
mkdir -p public
mkdir -p storage/{cache/docs,logs}
mkdir -p tests/{Markdown,Components}
```

- [ ] **Step 10: Run composer install**

```bash
composer install
```

Verify: `vendor/` directory created, autoload works.

- [ ] **Step 11: Generate APP_KEY**

```bash
php preflow key:generate
```

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -m "feat: bootstrap preflow-website project"
```

---

### Task 2: Theme System — CSS Custom Properties

**Files:**
- Create: `app/Components/ThemeToggle/ThemeToggle.php`
- Create: `app/Components/ThemeToggle/ThemeToggle.twig`

- [ ] **Step 1: Create ThemeToggle component PHP**

`app/Components/ThemeToggle/ThemeToggle.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\ThemeToggle;

use Preflow\Components\Component;
use Preflow\Core\Http\Session\SessionInterface;

final class ThemeToggle extends Component
{
    public string $theme = 'dark';

    public function __construct(
        private readonly SessionInterface $session,
    ) {}

    public function resolveState(): void
    {
        $this->theme = $this->session->get('preflow_theme', 'dark');
    }

    public function actions(): array
    {
        return ['toggle'];
    }

    public function actionToggle(array $params = []): void
    {
        $current = $this->session->get('preflow_theme', 'dark');
        $this->theme = $current === 'dark' ? 'light' : 'dark';
        $this->session->set('preflow_theme', $this->theme);
    }
}
```

- [ ] **Step 2: Create ThemeToggle template**

`app/Components/ThemeToggle/ThemeToggle.twig`:
```twig
<button class="theme-toggle" title="Toggle theme"
    {{ hd.post('toggle', componentClass, componentId, props) | raw }}
    hx-target="closest html"
    hx-select="html"
    hx-swap="outerHTML"
>
    {% if theme == 'dark' %}
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="5"/>
            <line x1="12" y1="1" x2="12" y2="3"/>
            <line x1="12" y1="21" x2="12" y2="23"/>
            <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
            <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
            <line x1="1" y1="12" x2="3" y2="12"/>
            <line x1="21" y1="12" x2="23" y2="12"/>
            <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
            <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>
    {% else %}
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
    {% endif %}
</button>

{% apply css %}
.theme-toggle {
    background: none;
    border: 1px solid var(--border);
    border-radius: 0.375rem;
    padding: 0.375rem;
    cursor: pointer;
    color: var(--text-secondary);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.15s, border-color 0.15s;
}

.theme-toggle:hover {
    color: var(--accent);
    border-color: var(--accent);
}
{% endapply %}
```

- [ ] **Step 3: Commit**

```bash
git add app/Components/ThemeToggle/
git commit -m "feat: add ThemeToggle component with session persistence"
```

---

### Task 3: Base Layout, Navigation, and Footer

**Files:**
- Create: `app/pages/_layout.twig`
- Create: `app/Components/Navigation/Navigation.php`
- Create: `app/Components/Navigation/Navigation.twig`
- Create: `app/Components/Footer/Footer.php`
- Create: `app/Components/Footer/Footer.twig`

- [ ] **Step 1: Create Navigation component PHP**

`app/Components/Navigation/Navigation.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\Navigation;

use Preflow\Components\Component;
use Preflow\Core\Http\RequestContext;
use Preflow\Core\Http\Session\SessionInterface;

final class Navigation extends Component
{
    protected string $tag = 'nav';

    /** @var array<int, array{path: string, label: string, active: bool}> */
    public array $items = [];
    public string $brand = '';
    public string $theme = 'dark';

    public function __construct(
        private readonly RequestContext $requestContext,
        private readonly SessionInterface $session,
    ) {}

    public function resolveState(): void
    {
        $currentPath = $this->requestContext->path;
        $this->brand = $this->props['brand'] ?? 'Preflow';
        $this->theme = $this->session->get('preflow_theme', 'dark');

        foreach ($this->props['items'] ?? [] as $item) {
            $path = $item['path'];
            if ($path === '/') {
                $active = $currentPath === '/';
            } else {
                $active = $currentPath === $path
                    || str_starts_with($currentPath, rtrim($path, '/') . '/');
            }
            $this->items[] = [...$item, 'active' => $active];
        }
    }
}
```

- [ ] **Step 2: Create Navigation template**

`app/Components/Navigation/Navigation.twig`:
```twig
<div class="nav-inner">
    <a href="/" class="nav-brand">
        <span class="nav-brand-icon">P</span>
        {{ brand }}
    </a>
    <div class="nav-links">
        {% for item in items %}
            <a href="{{ item.path }}" class="nav-link{{ item.active ? ' nav-link--active' : '' }}">
                {{ item.label }}
            </a>
        {% endfor %}
    </div>
    <div class="nav-actions">
        {{ component('ThemeToggle') }}
        <a href="https://github.com/getpreflow/preflow" class="nav-github" target="_blank" rel="noopener">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/>
            </svg>
        </a>
    </div>
</div>

{% apply css %}
.nav-inner {
    display: flex;
    align-items: center;
    padding: 0 2rem;
    height: 3.5rem;
    background: var(--bg-primary);
    border-bottom: 1px solid var(--border);
}

.nav-brand {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 700;
    font-size: 1.125rem;
    color: var(--text-primary);
    text-decoration: none;
}

.nav-brand-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    background: var(--accent);
    color: #fff;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    font-weight: 800;
}

.nav-links {
    display: flex;
    gap: 1.5rem;
    margin-left: 2.5rem;
}

.nav-link {
    color: var(--text-secondary);
    text-decoration: none;
    font-size: 0.875rem;
    padding: 0.25rem 0;
    border-bottom: 2px solid transparent;
    transition: color 0.15s, border-color 0.15s;
}

.nav-link:hover {
    color: var(--text-primary);
}

.nav-link--active {
    color: var(--accent);
    border-bottom-color: var(--accent);
    font-weight: 600;
}

.nav-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-left: auto;
}

.nav-github {
    color: var(--text-secondary);
    display: flex;
    transition: color 0.15s;
}

.nav-github:hover {
    color: var(--text-primary);
}

@media (max-width: 768px) {
    .nav-links {
        display: none;
    }
}
{% endapply %}
```

- [ ] **Step 3: Create Footer component PHP**

`app/Components/Footer/Footer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\Footer;

use Preflow\Components\Component;

final class Footer extends Component
{
    protected string $tag = 'footer';

    public int $year;

    public function resolveState(): void
    {
        $this->year = (int) date('Y');
    }
}
```

- [ ] **Step 4: Create Footer template**

`app/Components/Footer/Footer.twig`:
```twig
<div class="footer-inner">
    <div class="footer-grid">
        <div class="footer-col">
            <h4>Documentation</h4>
            <a href="/docs/getting-started/installation">Getting Started</a>
            <a href="/docs/guides/routing">Routing</a>
            <a href="/docs/guides/components">Components</a>
            <a href="/docs/guides/data">Data Layer</a>
        </div>
        <div class="footer-col">
            <h4>Packages</h4>
            <a href="/docs/packages/core">Core</a>
            <a href="/docs/packages/routing">Routing</a>
            <a href="/docs/packages/components">Components</a>
            <a href="/docs/packages/data">Data</a>
        </div>
        <div class="footer-col">
            <h4>Community</h4>
            <a href="https://github.com/getpreflow/preflow" target="_blank" rel="noopener">GitHub</a>
            <a href="https://packagist.org/packages/preflow/" target="_blank" rel="noopener">Packagist</a>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; {{ year }} Preflow. MIT License.</p>
        <p class="footer-built">Built with Preflow.</p>
    </div>
</div>

{% apply css %}
.footer-inner {
    padding: 3rem 2rem 2rem;
    background: var(--bg-primary);
    border-top: 1px solid var(--border);
}

.footer-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
    max-width: 64rem;
    margin: 0 auto;
}

.footer-col {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.footer-col h4 {
    color: var(--text-primary);
    font-size: 0.8125rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
}

.footer-col a {
    color: var(--text-muted);
    text-decoration: none;
    font-size: 0.875rem;
    transition: color 0.15s;
}

.footer-col a:hover {
    color: var(--accent);
}

.footer-bottom {
    max-width: 64rem;
    margin: 2rem auto 0;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--text-muted);
    font-size: 0.8125rem;
}

.footer-built {
    color: var(--accent);
}

@media (max-width: 640px) {
    .footer-grid {
        grid-template-columns: 1fr;
    }
}
{% endapply %}
```

- [ ] **Step 5: Create base layout**

`app/pages/_layout.twig`:
```twig
<!DOCTYPE html>
<html lang="en" data-theme="{{ theme ?? 'dark' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{% block title %}Preflow — The PHP framework that trusts the browser{% endblock %}</title>
    <meta name="description" content="{% block description %}Preflow is a component-first PHP framework with HTML-over-the-wire, zero build steps, and multi-storage ORM.{% endblock %}">
    {{ head() }}
</head>
<body>
    {{ component('Navigation', {
        brand: 'Preflow',
        items: [
            { path: '/', label: 'Home' },
            { path: '/docs', label: 'Docs' },
        ]
    }) }}

    {% block content %}{% endblock %}

    {{ component('Footer') }}

    {{ assets() }}
</body>
</html>

{% apply css %}
*, *::before, *::after {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

:root[data-theme="dark"] {
    --bg-primary: rgb(14, 28, 41);
    --bg-surface: rgb(50, 61, 104);
    --bg-card: rgba(94, 120, 143, 0.15);
    --bg-code: rgba(94, 120, 143, 0.2);
    --text-primary: rgb(255, 255, 255);
    --text-secondary: rgb(216, 223, 229);
    --text-muted: rgba(94, 120, 143, 0.5);
    --accent: rgb(119, 75, 229);
    --border: rgba(216, 223, 229, 0.15);
    --surface-hover: rgba(240, 248, 255, 0.08);
}

:root[data-theme="light"] {
    --bg-primary: rgb(246, 251, 255);
    --bg-surface: rgba(240, 248, 255, 0.9);
    --bg-card: rgb(255, 255, 255);
    --bg-code: rgb(246, 251, 255);
    --text-primary: rgb(14, 28, 41);
    --text-secondary: rgb(50, 61, 104);
    --text-muted: rgba(94, 120, 143, 0.5);
    --accent: rgb(119, 75, 229);
    --border: rgb(216, 223, 229);
    --surface-hover: rgba(240, 248, 255, 0.9);
}

body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    color: var(--text-primary);
    background: var(--bg-primary);
    line-height: 1.6;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    -webkit-font-smoothing: antialiased;
}

a {
    color: var(--accent);
}
{% endapply %}
```

Note: The `theme` variable in `data-theme="{{ theme ?? 'dark' }}"` needs to be injected. Add a global Twig variable via the `AppServiceProvider`. Update it to read the session:

`app/Providers/AppServiceProvider.php` (replace the empty one from Task 1):
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Preflow\Core\ServiceProvider;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\View\TemplateEngineInterface;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $engine = $this->container->get(TemplateEngineInterface::class);
        $session = $this->container->get(SessionInterface::class);
        $engine->addGlobal('theme', $session->get('preflow_theme', 'dark'));
    }
}
```

- [ ] **Step 6: Verify — start dev server and check the layout renders**

```bash
php preflow serve
```

Visit `http://localhost:8080`. Verify: dark background, navigation bar with "Preflow" brand, Home/Docs links, ThemeToggle button, GitHub icon, footer with links. Click the theme toggle — page should swap to light theme.

- [ ] **Step 7: Commit**

```bash
git add app/Components/Navigation/ app/Components/Footer/ app/Components/ThemeToggle/ app/pages/_layout.twig app/Providers/AppServiceProvider.php
git commit -m "feat: add base layout with Navigation, Footer, and ThemeToggle"
```

---

### Task 4: Landing Page Components — Hero

**Files:**
- Create: `app/Components/Hero/Hero.php`
- Create: `app/Components/Hero/Hero.twig`

- [ ] **Step 1: Create Hero component PHP**

`app/Components/Hero/Hero.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\Hero;

use Preflow\Components\Component;

final class Hero extends Component
{
    public string $headline = '';
    public string $subline = '';

    public function resolveState(): void
    {
        $this->headline = $this->props['headline'] ?? 'The PHP framework that trusts the browser.';
        $this->subline = $this->props['subline'] ?? 'Components. HTML over the wire. No build step.';
    }
}
```

- [ ] **Step 2: Create Hero template**

`app/Components/Hero/Hero.twig`:
```twig
<section class="hero">
    <div class="hero-inner">
        <h1 class="hero-headline">{{ headline }}</h1>
        <p class="hero-subline">{{ subline }}</p>
        <div class="hero-ctas">
            <a href="/docs/getting-started/installation" class="hero-btn hero-btn--primary">Get Started</a>
            <a href="/docs" class="hero-btn hero-btn--secondary">Documentation</a>
        </div>
    </div>
</section>

{% apply css %}
.hero {
    background: linear-gradient(180deg, var(--bg-primary) 0%, var(--bg-surface) 100%);
    padding: 6rem 2rem 5rem;
    text-align: center;
}

.hero-inner {
    max-width: 48rem;
    margin: 0 auto;
}

.hero-headline {
    font-size: clamp(2.5rem, 5vw, 4rem);
    font-weight: 800;
    letter-spacing: -0.03em;
    line-height: 1.1;
    color: var(--text-primary);
}

.hero-subline {
    margin-top: 1.25rem;
    font-size: clamp(1.125rem, 2vw, 1.375rem);
    color: var(--text-secondary);
    max-width: 36rem;
    margin-left: auto;
    margin-right: auto;
}

.hero-ctas {
    margin-top: 2.5rem;
    display: flex;
    gap: 1rem;
    justify-content: center;
    flex-wrap: wrap;
}

.hero-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.75rem 2rem;
    border-radius: 999px;
    font-size: 0.9375rem;
    font-weight: 600;
    text-decoration: none;
    transition: transform 0.15s, box-shadow 0.15s;
}

.hero-btn:hover {
    transform: translateY(-1px);
}

.hero-btn--primary {
    background: var(--accent);
    color: #fff;
    box-shadow: 0 4px 16px rgba(119, 75, 229, 0.3);
}

.hero-btn--primary:hover {
    box-shadow: 0 6px 24px rgba(119, 75, 229, 0.4);
}

.hero-btn--secondary {
    background: var(--bg-card);
    color: var(--text-secondary);
    border: 1px solid var(--border);
}

.hero-btn--secondary:hover {
    border-color: var(--accent);
    color: var(--text-primary);
}

@media (max-width: 640px) {
    .hero {
        padding: 4rem 1.5rem 3rem;
    }

    .hero-ctas {
        flex-direction: column;
        align-items: center;
    }
}
{% endapply %}
```

- [ ] **Step 3: Commit**

```bash
git add app/Components/Hero/
git commit -m "feat: add Hero component"
```

---

### Task 5: Landing Page Components — FeatureCard and FeatureGrid

**Files:**
- Create: `app/Components/FeatureCard/FeatureCard.php`
- Create: `app/Components/FeatureCard/FeatureCard.twig`
- Create: `app/Components/FeatureGrid/FeatureGrid.php`
- Create: `app/Components/FeatureGrid/FeatureGrid.twig`

- [ ] **Step 1: Create FeatureCard component PHP**

`app/Components/FeatureCard/FeatureCard.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\FeatureCard;

use Preflow\Components\Component;

final class FeatureCard extends Component
{
    public string $icon = '';
    public string $title = '';
    public string $description = '';

    public function resolveState(): void
    {
        $this->icon = $this->props['icon'] ?? '';
        $this->title = $this->props['title'] ?? '';
        $this->description = $this->props['description'] ?? '';
    }
}
```

- [ ] **Step 2: Create FeatureCard template**

`app/Components/FeatureCard/FeatureCard.twig`:
```twig
<div class="feature-card">
    <div class="feature-card-icon">{{ icon }}</div>
    <h3 class="feature-card-title">{{ title }}</h3>
    <p class="feature-card-desc">{{ description }}</p>
</div>

{% apply css %}
.feature-card {
    padding: 2rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 1rem;
    transition: transform 0.2s, border-color 0.2s;
}

.feature-card:hover {
    transform: translateY(-2px);
    border-color: var(--accent);
}

.feature-card-icon {
    font-size: 2rem;
    margin-bottom: 1rem;
    line-height: 1;
}

.feature-card-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.feature-card-desc {
    font-size: 0.875rem;
    color: var(--text-secondary);
    line-height: 1.6;
}
{% endapply %}
```

- [ ] **Step 3: Create FeatureGrid component PHP**

`app/Components/FeatureGrid/FeatureGrid.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\FeatureGrid;

use Preflow\Components\Component;

final class FeatureGrid extends Component
{
    /** @var array<int, array{icon: string, title: string, description: string}> */
    public array $features = [];

    public function resolveState(): void
    {
        $this->features = $this->props['features'] ?? [];
    }
}
```

- [ ] **Step 4: Create FeatureGrid template**

`app/Components/FeatureGrid/FeatureGrid.twig`:
```twig
<section class="feature-grid-section">
    <div class="feature-grid">
        {% for feature in features %}
            {{ component('FeatureCard', {
                icon: feature.icon,
                title: feature.title,
                description: feature.description
            }) }}
        {% endfor %}
    </div>
</section>

{% apply css %}
.feature-grid-section {
    padding: 4rem 2rem;
    max-width: 72rem;
    margin: 0 auto;
}

.feature-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

@media (max-width: 768px) {
    .feature-grid {
        grid-template-columns: 1fr;
    }
}
{% endapply %}
```

- [ ] **Step 5: Commit**

```bash
git add app/Components/FeatureCard/ app/Components/FeatureGrid/
git commit -m "feat: add FeatureCard and FeatureGrid components"
```

---

### Task 6: Landing Page Components — QuickStart and CodeExample

**Files:**
- Create: `app/Components/QuickStart/QuickStart.php`
- Create: `app/Components/QuickStart/QuickStart.twig`
- Create: `app/Components/CodeExample/CodeExample.php`
- Create: `app/Components/CodeExample/CodeExample.twig`

- [ ] **Step 1: Create QuickStart component PHP**

`app/Components/QuickStart/QuickStart.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\QuickStart;

use Preflow\Components\Component;

final class QuickStart extends Component
{
    /** @var array<int, array{label: string, code: string}> */
    public array $steps = [];

    public function resolveState(): void
    {
        $this->steps = [
            ['label' => 'Create your project', 'code' => 'composer create-project preflow/skeleton myapp'],
            ['label' => 'Start the dev server', 'code' => 'cd myapp && php preflow serve'],
            ['label' => 'Open in your browser', 'code' => 'http://localhost:8080'],
        ];
    }
}
```

- [ ] **Step 2: Create QuickStart template**

`app/Components/QuickStart/QuickStart.twig`:
```twig
<section class="quickstart">
    <h2 class="quickstart-title">Up and running in 30 seconds</h2>
    <div class="quickstart-steps">
        {% for step in steps %}
            <div class="quickstart-step">
                <div class="quickstart-num">{{ loop.index }}</div>
                <div class="quickstart-body">
                    <div class="quickstart-label">{{ step.label }}</div>
                    <code class="quickstart-code">{{ step.code }}</code>
                </div>
            </div>
        {% endfor %}
    </div>
</section>

{% apply css %}
.quickstart {
    padding: 4rem 2rem;
    max-width: 48rem;
    margin: 0 auto;
}

.quickstart-title {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
    text-align: center;
    margin-bottom: 2.5rem;
}

.quickstart-steps {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.quickstart-step {
    display: flex;
    align-items: flex-start;
    gap: 1.25rem;
    padding: 1.25rem 1.5rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
}

.quickstart-num {
    flex-shrink: 0;
    width: 2rem;
    height: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--accent);
    color: #fff;
    border-radius: 50%;
    font-size: 0.8125rem;
    font-weight: 700;
}

.quickstart-label {
    font-size: 0.8125rem;
    color: var(--text-muted);
    margin-bottom: 0.375rem;
}

.quickstart-code {
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
    font-size: 0.9375rem;
    color: var(--accent);
}
{% endapply %}
```

- [ ] **Step 3: Create CodeExample component PHP**

`app/Components/CodeExample/CodeExample.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\CodeExample;

use Preflow\Components\Component;
use Tempest\Highlight\Highlighter;

final class CodeExample extends Component
{
    public string $title = '';
    public string $language = 'php';
    public string $code = '';
    public string $highlighted = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? '';
        $this->language = $this->props['language'] ?? 'php';
        $this->code = $this->props['code'] ?? '';

        $highlighter = new Highlighter();
        $this->highlighted = $highlighter->parse($this->code, $this->language);
    }
}
```

- [ ] **Step 4: Create CodeExample template**

`app/Components/CodeExample/CodeExample.twig`:
```twig
<div class="code-example">
    {% if title %}
        <div class="code-example-header">
            <span class="code-example-title">{{ title }}</span>
            <span class="code-example-lang">{{ language }}</span>
        </div>
    {% endif %}
    <pre class="code-example-pre"><code>{{ highlighted | raw }}</code></pre>
</div>

{% apply css %}
.code-example {
    border-radius: 0.75rem;
    border: 1px solid var(--border);
    overflow: hidden;
}

.code-example-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.625rem 1rem;
    background: var(--bg-card);
    border-bottom: 1px solid var(--border);
}

.code-example-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-secondary);
}

.code-example-lang {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--text-muted);
}

.code-example-pre {
    padding: 1.25rem;
    background: var(--bg-code);
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
    font-size: 0.8125rem;
    line-height: 1.7;
    overflow-x: auto;
    color: var(--text-secondary);
}

.code-example-pre code {
    font-family: inherit;
}
{% endapply %}
```

- [ ] **Step 5: Commit**

```bash
git add app/Components/QuickStart/ app/Components/CodeExample/
git commit -m "feat: add QuickStart and CodeExample components"
```

---

### Task 7: Landing Page Components — ArchitectureDiagram and PackageCard

**Files:**
- Create: `app/Components/ArchitectureDiagram/ArchitectureDiagram.php`
- Create: `app/Components/ArchitectureDiagram/ArchitectureDiagram.twig`
- Create: `app/Components/PackageCard/PackageCard.php`
- Create: `app/Components/PackageCard/PackageCard.twig`

- [ ] **Step 1: Create ArchitectureDiagram component PHP**

`app/Components/ArchitectureDiagram/ArchitectureDiagram.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\ArchitectureDiagram;

use Preflow\Components\Component;

final class ArchitectureDiagram extends Component
{
    public function resolveState(): void
    {
    }
}
```

- [ ] **Step 2: Create ArchitectureDiagram template**

`app/Components/ArchitectureDiagram/ArchitectureDiagram.twig`:
```twig
<section class="arch">
    <h2 class="arch-title">How it works</h2>
    <p class="arch-sub">Every request flows through a clean, predictable pipeline.</p>
    <div class="arch-flow">
        <div class="arch-node">
            <div class="arch-node-label">Request</div>
        </div>
        <div class="arch-arrow">&rarr;</div>
        <div class="arch-node">
            <div class="arch-node-label">Middleware</div>
            <div class="arch-node-desc">Session, CSRF, i18n</div>
        </div>
        <div class="arch-arrow">&rarr;</div>
        <div class="arch-node arch-node--kernel">
            <div class="arch-node-label">Kernel</div>
        </div>
        <div class="arch-arrow">&rarr;</div>
        <div class="arch-modes">
            <div class="arch-node arch-node--mode">
                <div class="arch-node-label">Component Mode</div>
                <div class="arch-node-desc">Pages &amp; templates</div>
            </div>
            <div class="arch-mode-or">or</div>
            <div class="arch-node arch-node--mode">
                <div class="arch-node-label">Action Mode</div>
                <div class="arch-node-desc">Controllers &amp; APIs</div>
            </div>
        </div>
        <div class="arch-arrow">&rarr;</div>
        <div class="arch-node">
            <div class="arch-node-label">Response</div>
        </div>
    </div>
</section>

{% apply css %}
.arch {
    padding: 4rem 2rem;
    max-width: 72rem;
    margin: 0 auto;
    text-align: center;
}

.arch-title {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
}

.arch-sub {
    color: var(--text-secondary);
    margin-top: 0.5rem;
    margin-bottom: 3rem;
}

.arch-flow {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.75rem;
    flex-wrap: wrap;
}

.arch-node {
    padding: 1rem 1.5rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    min-width: 7rem;
}

.arch-node--kernel {
    border-color: var(--accent);
    background: rgba(119, 75, 229, 0.1);
}

.arch-node-label {
    font-weight: 700;
    font-size: 0.875rem;
    color: var(--text-primary);
}

.arch-node-desc {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.25rem;
}

.arch-arrow {
    color: var(--accent);
    font-size: 1.25rem;
    font-weight: 700;
}

.arch-modes {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
}

.arch-node--mode {
    padding: 0.75rem 1.25rem;
}

.arch-mode-or {
    font-size: 0.75rem;
    color: var(--text-muted);
    font-style: italic;
}

@media (max-width: 768px) {
    .arch-flow {
        flex-direction: column;
    }

    .arch-arrow {
        transform: rotate(90deg);
    }
}
{% endapply %}
```

- [ ] **Step 3: Create PackageCard component PHP**

`app/Components/PackageCard/PackageCard.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\PackageCard;

use Preflow\Components\Component;

final class PackageCard extends Component
{
    public string $name = '';
    public string $description = '';
    public string $docsPath = '';

    public function resolveState(): void
    {
        $this->name = $this->props['name'] ?? '';
        $this->description = $this->props['description'] ?? '';
        $this->docsPath = $this->props['docsPath'] ?? '#';
    }
}
```

- [ ] **Step 4: Create PackageCard template**

`app/Components/PackageCard/PackageCard.twig`:
```twig
<a href="{{ docsPath }}" class="package-card">
    <div class="package-card-name">preflow/{{ name }}</div>
    <div class="package-card-desc">{{ description }}</div>
</a>

{% apply css %}
.package-card {
    display: block;
    padding: 1.25rem 1.5rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    text-decoration: none;
    transition: transform 0.2s, border-color 0.2s;
}

.package-card:hover {
    transform: translateY(-2px);
    border-color: var(--accent);
}

.package-card-name {
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--accent);
    margin-bottom: 0.375rem;
}

.package-card-desc {
    font-size: 0.8125rem;
    color: var(--text-secondary);
    line-height: 1.5;
}
{% endapply %}
```

- [ ] **Step 5: Commit**

```bash
git add app/Components/ArchitectureDiagram/ app/Components/PackageCard/
git commit -m "feat: add ArchitectureDiagram and PackageCard components"
```

---

### Task 8: Assemble Landing Page

**Files:**
- Create: `app/pages/index.twig`

- [ ] **Step 1: Create the landing page**

`app/pages/index.twig`:
```twig
{% extends "_layout.twig" %}

{% block title %}Preflow — The PHP framework that trusts the browser{% endblock %}

{% block content %}
{{ component('Hero', {
    headline: 'The PHP framework that trusts the browser.',
    subline: 'Components. HTML over the wire. No build step. No JS framework.'
}) }}

{{ component('FeatureGrid', {
    features: [
        {
            icon: '&#x1f9e9;',
            title: 'Component Architecture',
            description: 'PHP class + template + CSS in one directory. Co-located, auto-discovered, self-contained. Every component is a unit.'
        },
        {
            icon: '&#x1f310;',
            title: 'HTML Over the Wire',
            description: 'The server renders HTML, HTMX swaps it in. No JSON serialization. No client-side state management. The browser does what it was built to do.'
        },
        {
            icon: '&#x26a1;',
            title: 'Zero External Requests',
            description: 'All CSS and JS are inlined in the HTML document. Hash-deduplicated. CSP nonces on every tag. No bundler, no build step.'
        },
        {
            icon: '&#x1f4be;',
            title: 'Multi-Storage ORM',
            description: 'SQLite, JSON files, or MySQL — same query API. Each model picks its storage backend via a PHP attribute. Mix and match.'
        },
        {
            icon: '&#x1f504;',
            title: 'Template Engine Freedom',
            description: 'Twig by default. Blade as an alternative. Both sit behind the same interface — swap with one config change, no code rewrites.'
        },
        {
            icon: '&#x1f512;',
            title: 'Security First',
            description: 'HMAC-signed component tokens, CSRF protection, error boundaries, CSP nonces, session fixation prevention. Secure by default.'
        }
    ]
}) }}

{{ component('QuickStart') }}

<section class="landing-code-examples">
    <h2 class="landing-section-title">Clean, expressive code</h2>
    <div class="landing-code-grid">
        {{ component('CodeExample', {
            title: 'ExampleCard.php',
            language: 'php',
            code: "<?php\n\nfinal class ExampleCard extends Component\n{\n    public string $title = '';\n    public int $count = 0;\n\n    public function resolveState(): void\n    {\n        $this->title = $this->props['title'] ?? 'Hello';\n        $this->count = (int) $this->session->get('counter', 0);\n    }\n\n    public function actions(): array\n    {\n        return ['increment'];\n    }\n\n    public function actionIncrement(): void\n    {\n        $this->count++;\n        $this->session->set('counter', $this->count);\n    }\n}"
        }) }}

        {{ component('CodeExample', {
            title: 'ExampleCard.twig',
            language: 'html',
            code: "<div class=\"card\">\n    <h2>{{ '{{' }} title {{ '}}' }}</h2>\n    <p>Count: {{ '{{' }} count {{ '}}' }}</p>\n    <button {{ '{{' }} hd.post('increment') | raw {{ '}}' }}>+1</button>\n</div>\n\n{{ '{%' }} apply css {{ '%}' }}\n.card {\n    padding: 2rem;\n    border-radius: 0.5rem;\n    background: var(--bg-card);\n}\n{{ '{%' }} endapply {{ '%}' }}"
        }) }}

        {{ component('CodeExample', {
            title: 'Post.php — Model',
            language: 'php',
            code: "<?php\n\n#[Entity(table: 'posts', storage: 'sqlite')]\nfinal class Post extends Model\n{\n    #[Id]\n    public string $uuid = '';\n\n    #[Field(searchable: true)]\n    public string $title = '';\n\n    #[Field]\n    public string $slug = '';\n\n    #[Field]\n    public string $status = 'draft';\n\n    #[Field]\n    public ?string $created_at = null;\n}"
        }) }}
    </div>
</section>

{{ component('ArchitectureDiagram') }}

<section class="landing-packages">
    <h2 class="landing-section-title">13 packages, one framework</h2>
    <p class="landing-section-sub">Every package has a single job. Require what you need, skip what you don't.</p>
    <div class="package-grid">
        {{ component('PackageCard', { name: 'core', description: 'DI container, config, middleware pipeline, error handling', docsPath: '/docs/packages/core' }) }}
        {{ component('PackageCard', { name: 'routing', description: 'File-based + attribute hybrid router with caching', docsPath: '/docs/packages/routing' }) }}
        {{ component('PackageCard', { name: 'view', description: 'Template engine interface, asset collector, CSP nonces', docsPath: '/docs/packages/view' }) }}
        {{ component('PackageCard', { name: 'components', description: 'Component lifecycle, actions, error boundaries', docsPath: '/docs/packages/components' }) }}
        {{ component('PackageCard', { name: 'twig', description: 'Twig 3 adapter with co-located CSS/JS filters', docsPath: '/docs/packages/twig' }) }}
        {{ component('PackageCard', { name: 'blade', description: 'Laravel Blade adapter — same interface, different syntax', docsPath: '/docs/packages/blade' }) }}
        {{ component('PackageCard', { name: 'data', description: 'Multi-storage ORM: SQLite, JSON, MySQL', docsPath: '/docs/packages/data' }) }}
        {{ component('PackageCard', { name: 'htmx', description: 'Hypermedia driver with HMAC-signed tokens', docsPath: '/docs/packages/htmx' }) }}
        {{ component('PackageCard', { name: 'i18n', description: 'Translations, pluralization, locale detection', docsPath: '/docs/packages/i18n' }) }}
        {{ component('PackageCard', { name: 'auth', description: 'Session/token guards, CSRF, password hashing', docsPath: '/docs/packages/auth' }) }}
        {{ component('PackageCard', { name: 'devtools', description: 'CLI: serve, migrate, make:*, routes:list', docsPath: '/docs/packages/devtools' }) }}
        {{ component('PackageCard', { name: 'testing', description: 'Component, route, and data test cases', docsPath: '/docs/packages/testing' }) }}
        {{ component('PackageCard', { name: 'skeleton', description: 'Project starter template — composer create-project and go', docsPath: '/docs/packages/skeleton' }) }}
    </div>
</section>
{% endblock %}

{% apply css %}
.landing-section-title {
    font-size: 1.75rem;
    font-weight: 800;
    color: var(--text-primary);
    text-align: center;
}

.landing-section-sub {
    text-align: center;
    color: var(--text-secondary);
    margin-top: 0.5rem;
}

.landing-code-examples {
    padding: 4rem 2rem;
    max-width: 72rem;
    margin: 0 auto;
}

.landing-code-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr));
    gap: 1.5rem;
    margin-top: 2.5rem;
}

.landing-packages {
    padding: 4rem 2rem;
    max-width: 72rem;
    margin: 0 auto;
}

.package-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
    gap: 1rem;
    margin-top: 2.5rem;
}

@media (max-width: 640px) {
    .landing-code-grid {
        grid-template-columns: 1fr;
    }
}
{% endapply %}
```

- [ ] **Step 2: Verify — start dev server and check the full landing page**

```bash
php preflow serve
```

Visit `http://localhost:8080`. Verify all sections render: Hero with gradient, feature grid (6 cards, 2-column), QuickStart (3 steps), code examples (3 panels with syntax highlighting), architecture diagram, package cards (13 cards). Toggle theme — all sections should swap cleanly. Check mobile responsiveness at 375px width.

- [ ] **Step 3: Commit**

```bash
git add app/pages/index.twig
git commit -m "feat: assemble landing page with all sections"
```

---

### Task 9: Markdown Pipeline — FrontmatterExtractor and SyntaxHighlightExtension

**Files:**
- Create: `app/Markdown/FrontmatterExtractor.php`
- Create: `app/Markdown/SyntaxHighlightExtension.php`
- Create: `tests/Markdown/FrontmatterExtractorTest.php`
- Create: `tests/Markdown/SyntaxHighlightExtensionTest.php`

- [ ] **Step 1: Write failing test for FrontmatterExtractor**

`tests/Markdown/FrontmatterExtractorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Markdown;

use App\Markdown\FrontmatterExtractor;
use PHPUnit\Framework\TestCase;

final class FrontmatterExtractorTest extends TestCase
{
    public function test_extracts_frontmatter_and_body(): void
    {
        $markdown = <<<'MD'
        ---
        title: Routing
        description: File-based and attribute-based routing
        group: guides
        order: 1
        ---

        # Routing

        Content here.
        MD;

        $result = FrontmatterExtractor::extract($markdown);

        $this->assertSame('Routing', $result['meta']['title']);
        $this->assertSame('File-based and attribute-based routing', $result['meta']['description']);
        $this->assertSame('guides', $result['meta']['group']);
        $this->assertSame(1, $result['meta']['order']);
        $this->assertStringContainsString('# Routing', $result['body']);
        $this->assertStringNotContainsString('---', $result['body']);
    }

    public function test_handles_no_frontmatter(): void
    {
        $markdown = "# Just a heading\n\nSome content.";

        $result = FrontmatterExtractor::extract($markdown);

        $this->assertSame([], $result['meta']);
        $this->assertStringContainsString('# Just a heading', $result['body']);
    }

    public function test_handles_empty_frontmatter(): void
    {
        $markdown = "---\n---\n\n# Content";

        $result = FrontmatterExtractor::extract($markdown);

        $this->assertSame([], $result['meta']);
        $this->assertStringContainsString('# Content', $result['body']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Markdown/FrontmatterExtractorTest.php -v
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement FrontmatterExtractor**

`app/Markdown/FrontmatterExtractor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Markdown;

final class FrontmatterExtractor
{
    /**
     * @return array{meta: array<string, mixed>, body: string}
     */
    public static function extract(string $markdown): array
    {
        $markdown = ltrim($markdown);

        if (!str_starts_with($markdown, '---')) {
            return ['meta' => [], 'body' => $markdown];
        }

        $parts = preg_split('/^---\s*$/m', $markdown, 3);

        if ($parts === false || count($parts) < 3) {
            return ['meta' => [], 'body' => $markdown];
        }

        $rawMeta = trim($parts[1]);
        $body = ltrim($parts[2]);

        if ($rawMeta === '') {
            return ['meta' => [], 'body' => $body];
        }

        $meta = [];
        foreach (explode("\n", $rawMeta) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (is_numeric($value)) {
                $value = str_contains($value, '.') ? (float) $value : (int) $value;
            }

            $meta[$key] = $value;
        }

        return ['meta' => $meta, 'body' => $body];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Markdown/FrontmatterExtractorTest.php -v
```

Expected: 3 tests, 3 passed.

- [ ] **Step 5: Write failing test for SyntaxHighlightExtension**

`tests/Markdown/SyntaxHighlightExtensionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Markdown;

use App\Markdown\SyntaxHighlightExtension;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;
use PHPUnit\Framework\TestCase;

final class SyntaxHighlightExtensionTest extends TestCase
{
    public function test_highlights_php_code_blocks(): void
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new SyntaxHighlightExtension());

        $converter = new MarkdownConverter($env);
        $markdown = "```php\n<?php\necho 'hello';\n```";

        $html = $converter->convert($markdown)->getContent();

        $this->assertStringContainsString('<pre', $html);
        $this->assertStringContainsString('<code', $html);
        // tempest/highlight wraps tokens in spans
        $this->assertStringContainsString('<span', $html);
    }

    public function test_passes_through_plain_code_blocks(): void
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new SyntaxHighlightExtension());

        $converter = new MarkdownConverter($env);
        $markdown = "```\nplain text here\n```";

        $html = $converter->convert($markdown)->getContent();

        $this->assertStringContainsString('plain text here', $html);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Markdown/SyntaxHighlightExtensionTest.php -v
```

Expected: FAIL — class not found.

- [ ] **Step 7: Implement SyntaxHighlightExtension**

`app/Markdown/SyntaxHighlightExtension.php`:
```php
<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use Tempest\Highlight\Highlighter;

final class SyntaxHighlightExtension implements ExtensionInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(FencedCode::class, new class implements NodeRendererInterface {
            private Highlighter $highlighter;

            public function __construct()
            {
                $this->highlighter = new Highlighter();
            }

            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                assert($node instanceof FencedCode);

                $language = $node->getInfo() ?? '';
                $code = $node->getLiteral();

                if ($language !== '' && $code !== null) {
                    $highlighted = $this->highlighter->parse($code, $language);
                    return '<pre class="code-block code-block--' . htmlspecialchars($language) . '">'
                        . '<code>' . $highlighted . '</code></pre>';
                }

                $escaped = htmlspecialchars($code ?? '', ENT_QUOTES, 'UTF-8');
                return '<pre class="code-block"><code>' . $escaped . '</code></pre>';
            }
        }, 10);
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Markdown/SyntaxHighlightExtensionTest.php -v
```

Expected: 2 tests, 2 passed.

- [ ] **Step 9: Commit**

```bash
git add app/Markdown/ tests/Markdown/
git commit -m "feat: add FrontmatterExtractor and SyntaxHighlightExtension with tests"
```

---

### Task 10: Markdown Pipeline — MarkdownParser

**Files:**
- Create: `app/Markdown/MarkdownParser.php`
- Create: `tests/Markdown/MarkdownParserTest.php`

- [ ] **Step 1: Write failing test for MarkdownParser**

`tests/Markdown/MarkdownParserTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Markdown;

use App\Markdown\MarkdownParser;
use PHPUnit\Framework\TestCase;

final class MarkdownParserTest extends TestCase
{
    private MarkdownParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MarkdownParser();
    }

    public function test_parses_markdown_with_frontmatter(): void
    {
        $markdown = <<<'MD'
        ---
        title: Routing
        description: How routing works
        group: guides
        order: 1
        ---

        # Routing

        Preflow uses file-based routing.

        ```php
        <?php
        echo 'hello';
        ```
        MD;

        $result = $this->parser->parse($markdown);

        $this->assertSame('Routing', $result['meta']['title']);
        $this->assertStringContainsString('<h1>Routing</h1>', $result['html']);
        $this->assertStringContainsString('<pre', $result['html']);
        // headings extracted for TOC
        $this->assertNotEmpty($result['headings']);
        $this->assertSame('Routing', $result['headings'][0]['text']);
        $this->assertSame(1, $result['headings'][0]['level']);
    }

    public function test_extracts_h2_and_h3_headings(): void
    {
        $markdown = "## First\n\nText.\n\n### Nested\n\nMore text.\n\n## Second";

        $result = $this->parser->parse($markdown);

        $this->assertCount(3, $result['headings']);
        $this->assertSame('First', $result['headings'][0]['text']);
        $this->assertSame(2, $result['headings'][0]['level']);
        $this->assertSame('Nested', $result['headings'][1]['text']);
        $this->assertSame(3, $result['headings'][1]['level']);
        $this->assertSame('Second', $result['headings'][2]['text']);
    }

    public function test_generates_heading_ids(): void
    {
        $markdown = "## Getting Started\n\n### Quick Start Guide";

        $result = $this->parser->parse($markdown);

        $this->assertStringContainsString('id="getting-started"', $result['html']);
        $this->assertStringContainsString('id="quick-start-guide"', $result['html']);
        $this->assertSame('getting-started', $result['headings'][0]['id']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit tests/Markdown/MarkdownParserTest.php -v
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement MarkdownParser**

`app/Markdown/MarkdownParser.php`:
```php
<?php

declare(strict_types=1);

namespace App\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownParser
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $env = new Environment();
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new SyntaxHighlightExtension());

        $this->converter = new MarkdownConverter($env);
    }

    /**
     * @return array{meta: array<string, mixed>, html: string, headings: array<int, array{text: string, level: int, id: string}>}
     */
    public function parse(string $markdown): array
    {
        $extracted = FrontmatterExtractor::extract($markdown);
        $html = $this->converter->convert($extracted['body'])->getContent();

        $headings = [];
        $html = preg_replace_callback(
            '/<h([1-6])>(.*?)<\/h[1-6]>/i',
            function (array $matches) use (&$headings): string {
                $level = (int) $matches[1];
                $text = strip_tags($matches[2]);
                $id = $this->slugify($text);

                $headings[] = [
                    'text' => $text,
                    'level' => $level,
                    'id' => $id,
                ];

                return '<h' . $level . ' id="' . htmlspecialchars($id) . '">' . $matches[2] . '</h' . $level . '>';
            },
            $html
        ) ?? $html;

        return [
            'meta' => $extracted['meta'],
            'html' => $html,
            'headings' => $headings,
        ];
    }

    private function slugify(string $text): string
    {
        $slug = strtolower($text);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/[\s-]+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit tests/Markdown/MarkdownParserTest.php -v
```

Expected: 3 tests, 3 passed.

- [ ] **Step 5: Commit**

```bash
git add app/Markdown/MarkdownParser.php tests/Markdown/MarkdownParserTest.php
git commit -m "feat: add MarkdownParser with heading extraction and ID generation"
```

---

### Task 11: DocsServiceProvider

**Files:**
- Create: `app/Providers/DocsServiceProvider.php`

- [ ] **Step 1: Create DocsServiceProvider**

`app/Providers/DocsServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Markdown\MarkdownParser;
use Preflow\Core\ServiceProvider;

final class DocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(MarkdownParser::class, fn () => new MarkdownParser());

        $this->container->singleton('docs.manifest', function () {
            $path = $this->container->get('path.base') . '/docs/manifest.php';
            return file_exists($path) ? require $path : [];
        });

        $this->container->singleton('docs.base_path', function () {
            return $this->container->get('path.base') . '/docs';
        });

        $this->container->singleton('docs.cache_path', function () {
            return $this->container->get('path.base') . '/storage/cache/docs';
        });
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Providers/DocsServiceProvider.php
git commit -m "feat: add DocsServiceProvider with markdown parser and manifest bindings"
```

---

### Task 12: Docs Components — DocsSidebar

**Files:**
- Create: `app/Components/DocsSidebar/DocsSidebar.php`
- Create: `app/Components/DocsSidebar/DocsSidebar.twig`
- Create: `docs/manifest.php`

- [ ] **Step 1: Create manifest.php**

`docs/manifest.php`:
```php
<?php

return [
    'Getting Started' => [
        'getting-started/installation',
        'getting-started/configuration',
        'getting-started/directory-structure',
    ],
    'Guides' => [
        'guides/routing',
        'guides/components',
        'guides/data',
        'guides/authentication',
        'guides/internationalization',
        'guides/testing',
    ],
    'Packages' => [
        'packages/core',
        'packages/routing',
        'packages/view',
        'packages/components',
        'packages/twig',
        'packages/blade',
        'packages/data',
        'packages/htmx',
        'packages/i18n',
        'packages/auth',
        'packages/devtools',
        'packages/testing',
        'packages/skeleton',
    ],
];
```

- [ ] **Step 2: Create DocsSidebar component PHP**

`app/Components/DocsSidebar/DocsSidebar.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\DocsSidebar;

use App\Markdown\FrontmatterExtractor;
use Preflow\Components\Component;
use Preflow\Core\Http\RequestContext;

final class DocsSidebar extends Component
{
    /** @var array<string, array<int, array{path: string, title: string, active: bool}>> */
    public array $groups = [];
    public string $currentPath = '';

    public function __construct(
        private readonly RequestContext $requestContext,
    ) {}

    public function resolveState(): void
    {
        $manifest = $this->props['manifest'] ?? [];
        $basePath = $this->props['basePath'] ?? '';
        $this->currentPath = $this->props['currentPath'] ?? '';

        foreach ($manifest as $group => $paths) {
            $items = [];
            foreach ($paths as $docPath) {
                $filePath = $basePath . '/' . $docPath . '.md';
                $title = $this->extractTitle($filePath, $docPath);
                $active = $this->currentPath === $docPath;
                $items[] = [
                    'path' => '/docs/' . $docPath,
                    'title' => $title,
                    'active' => $active,
                ];
            }
            $this->groups[$group] = $items;
        }
    }

    private function extractTitle(string $filePath, string $fallback): string
    {
        if (!file_exists($filePath)) {
            return ucfirst(basename($fallback));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ucfirst(basename($fallback));
        }

        // Read only the frontmatter, not the full file
        $result = FrontmatterExtractor::extract($content);
        return $result['meta']['title'] ?? ucfirst(basename($fallback));
    }
}
```

- [ ] **Step 3: Create DocsSidebar template**

`app/Components/DocsSidebar/DocsSidebar.twig`:
```twig
<aside class="docs-sidebar">
    <div class="docs-sidebar-inner">
        {% for group, items in groups %}
            <div class="docs-sidebar-group">
                <button class="docs-sidebar-group-title" onclick="this.parentElement.classList.toggle('collapsed')">
                    {{ group }}
                    <svg class="docs-sidebar-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="docs-sidebar-links">
                    {% for item in items %}
                        <a href="{{ item.path }}" class="docs-sidebar-link{{ item.active ? ' docs-sidebar-link--active' : '' }}">
                            {{ item.title }}
                        </a>
                    {% endfor %}
                </div>
            </div>
        {% endfor %}
    </div>
</aside>

{% apply css %}
.docs-sidebar {
    width: 15rem;
    flex-shrink: 0;
    position: sticky;
    top: 3.5rem;
    height: calc(100vh - 3.5rem);
    overflow-y: auto;
    padding: 1.5rem 0;
    border-right: 1px solid var(--border);
    background: var(--bg-primary);
}

.docs-sidebar-inner {
    padding: 0 1rem;
}

.docs-sidebar-group {
    margin-bottom: 1.25rem;
}

.docs-sidebar-group.collapsed .docs-sidebar-links {
    display: none;
}

.docs-sidebar-group.collapsed .docs-sidebar-chevron {
    transform: rotate(-90deg);
}

.docs-sidebar-group-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    background: none;
    border: none;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    padding: 0.375rem 0.5rem;
    cursor: pointer;
}

.docs-sidebar-chevron {
    transition: transform 0.15s;
}

.docs-sidebar-links {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
    margin-top: 0.25rem;
}

.docs-sidebar-link {
    display: block;
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
    color: var(--text-secondary);
    text-decoration: none;
    border-radius: 0.375rem;
    transition: color 0.15s, background 0.15s;
}

.docs-sidebar-link:hover {
    color: var(--text-primary);
    background: var(--surface-hover);
}

.docs-sidebar-link--active {
    color: var(--accent);
    background: rgba(119, 75, 229, 0.1);
    font-weight: 600;
}

@media (max-width: 768px) {
    .docs-sidebar {
        display: none;
    }
}
{% endapply %}
```

- [ ] **Step 4: Commit**

```bash
git add app/Components/DocsSidebar/ docs/manifest.php
git commit -m "feat: add DocsSidebar component with collapsible groups and manifest"
```

---

### Task 13: Docs Components — TableOfContents and DocsPage

**Files:**
- Create: `app/Components/TableOfContents/TableOfContents.php`
- Create: `app/Components/TableOfContents/TableOfContents.twig`
- Create: `app/Components/DocsPage/DocsPage.php`
- Create: `app/Components/DocsPage/DocsPage.twig`

- [ ] **Step 1: Create TableOfContents component PHP**

`app/Components/TableOfContents/TableOfContents.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\TableOfContents;

use Preflow\Components\Component;

final class TableOfContents extends Component
{
    /** @var array<int, array{text: string, level: int, id: string}> */
    public array $headings = [];

    public function resolveState(): void
    {
        $all = $this->props['headings'] ?? [];
        // Only include h2 and h3 for the TOC
        $this->headings = array_values(array_filter(
            $all,
            fn (array $h) => $h['level'] >= 2 && $h['level'] <= 3,
        ));
    }
}
```

- [ ] **Step 2: Create TableOfContents template**

`app/Components/TableOfContents/TableOfContents.twig`:
```twig
{% if headings|length > 1 %}
<nav class="toc">
    <div class="toc-title">On this page</div>
    <ul class="toc-list">
        {% for heading in headings %}
            <li class="toc-item toc-item--h{{ heading.level }}">
                <a href="#{{ heading.id }}" class="toc-link">{{ heading.text }}</a>
            </li>
        {% endfor %}
    </ul>
</nav>
{% endif %}

{% apply css %}
.toc {
    width: 12.5rem;
    flex-shrink: 0;
    position: sticky;
    top: 4.5rem;
    max-height: calc(100vh - 5rem);
    overflow-y: auto;
    padding: 0 1rem;
}

.toc-title {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    margin-bottom: 0.75rem;
}

.toc-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.toc-item {
    margin-bottom: 0.25rem;
}

.toc-item--h3 {
    padding-left: 0.75rem;
}

.toc-link {
    display: block;
    font-size: 0.8125rem;
    color: var(--text-muted);
    text-decoration: none;
    padding: 0.2rem 0;
    border-left: 2px solid transparent;
    padding-left: 0.5rem;
    transition: color 0.15s, border-color 0.15s;
}

.toc-link:hover {
    color: var(--text-primary);
    border-left-color: var(--accent);
}

@media (max-width: 1024px) {
    .toc {
        display: none;
    }
}
{% endapply %}
```

- [ ] **Step 3: Create DocsPage component PHP**

`app/Components/DocsPage/DocsPage.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\DocsPage;

use App\Markdown\MarkdownParser;
use Preflow\Components\Component;

final class DocsPage extends Component
{
    public string $title = '';
    public string $description = '';
    public string $html = '';
    /** @var array<int, array{text: string, level: int, id: string}> */
    public array $headings = [];
    public bool $found = true;

    /** Navigation */
    public ?string $prevPath = null;
    public ?string $prevTitle = null;
    public ?string $nextPath = null;
    public ?string $nextTitle = null;

    public function __construct(
        private readonly MarkdownParser $parser,
    ) {}

    public function resolveState(): void
    {
        $docPath = $this->props['docPath'] ?? '';
        $basePath = $this->props['basePath'] ?? '';
        $manifest = $this->props['manifest'] ?? [];

        $filePath = $basePath . '/' . $docPath . '.md';

        if (!file_exists($filePath)) {
            $this->found = false;
            $this->title = 'Not Found';
            $this->html = '<p>This documentation page does not exist yet.</p>';
            return;
        }

        $cachePath = $this->props['cachePath'] ?? '';
        $cached = $this->loadFromCache($cachePath, $filePath, $docPath);

        if ($cached !== null) {
            $this->applyResult($cached);
        } else {
            $content = file_get_contents($filePath);
            $result = $this->parser->parse($content);
            $this->applyResult($result);
            $this->writeCache($cachePath, $filePath, $docPath, $result);
        }

        $this->resolveNavigation($docPath, $manifest);
    }

    /**
     * @param array{meta: array<string, mixed>, html: string, headings: array<int, array{text: string, level: int, id: string}>} $result
     */
    private function applyResult(array $result): void
    {
        $this->title = $result['meta']['title'] ?? '';
        $this->description = $result['meta']['description'] ?? '';
        $this->html = $result['html'];
        $this->headings = $result['headings'];
    }

    /**
     * @return array{meta: array<string, mixed>, html: string, headings: array<int, array{text: string, level: int, id: string}>}|null
     */
    private function loadFromCache(string $cachePath, string $filePath, string $docPath): ?array
    {
        if ($cachePath === '') {
            return null;
        }

        $cacheFile = $cachePath . '/' . str_replace('/', '_', $docPath) . '.json';

        if (!file_exists($cacheFile)) {
            return null;
        }

        $cached = json_decode(file_get_contents($cacheFile), true);

        if (!is_array($cached) || ($cached['mtime'] ?? 0) !== filemtime($filePath)) {
            return null;
        }

        return $cached['data'] ?? null;
    }

    /**
     * @param array{meta: array<string, mixed>, html: string, headings: array<int, array{text: string, level: int, id: string}>} $result
     */
    private function writeCache(string $cachePath, string $filePath, string $docPath, array $result): void
    {
        if ($cachePath === '') {
            return;
        }

        if (!is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        $cacheFile = $cachePath . '/' . str_replace('/', '_', $docPath) . '.json';
        file_put_contents($cacheFile, json_encode([
            'mtime' => filemtime($filePath),
            'data' => $result,
        ]));
    }

    /**
     * @param array<string, array<int, string>> $manifest
     */
    private function resolveNavigation(string $docPath, array $manifest): void
    {
        $flat = [];
        foreach ($manifest as $paths) {
            foreach ($paths as $p) {
                $flat[] = $p;
            }
        }

        $index = array_search($docPath, $flat, true);
        if ($index === false) {
            return;
        }

        if ($index > 0) {
            $prev = $flat[$index - 1];
            $this->prevPath = '/docs/' . $prev;
            $this->prevTitle = ucfirst(basename($prev));
        }

        if ($index < count($flat) - 1) {
            $next = $flat[$index + 1];
            $this->nextPath = '/docs/' . $next;
            $this->nextTitle = ucfirst(basename($next));
        }
    }
}
```

- [ ] **Step 4: Create DocsPage template**

`app/Components/DocsPage/DocsPage.twig`:
```twig
<div class="docs-page">
    {% if not found %}
        <div class="docs-not-found">
            <h1>Page not found</h1>
            <p>This documentation page doesn't exist yet.</p>
            <a href="/docs/getting-started/installation">Go to Getting Started</a>
        </div>
    {% else %}
        <article class="docs-content">
            {{ html | raw }}
        </article>

        {% if prevPath or nextPath %}
            <nav class="docs-nav-bottom">
                {% if prevPath %}
                    <a href="{{ prevPath }}" class="docs-nav-prev">
                        <span class="docs-nav-label">&larr; Previous</span>
                        <span class="docs-nav-title">{{ prevTitle }}</span>
                    </a>
                {% else %}
                    <div></div>
                {% endif %}
                {% if nextPath %}
                    <a href="{{ nextPath }}" class="docs-nav-next">
                        <span class="docs-nav-label">Next &rarr;</span>
                        <span class="docs-nav-title">{{ nextTitle }}</span>
                    </a>
                {% endif %}
            </nav>
        {% endif %}
    {% endif %}
</div>

{% apply css %}
.docs-page {
    flex: 1;
    min-width: 0;
    padding: 2rem 3rem;
    max-width: 48rem;
}

.docs-content h1 {
    font-size: 2rem;
    font-weight: 800;
    color: var(--text-primary);
    margin-bottom: 1rem;
    letter-spacing: -0.02em;
}

.docs-content h2 {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-top: 2.5rem;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--border);
}

.docs-content h3 {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-top: 2rem;
    margin-bottom: 0.5rem;
}

.docs-content p {
    margin-bottom: 1rem;
    color: var(--text-secondary);
    line-height: 1.75;
}

.docs-content a {
    color: var(--accent);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.docs-content ul, .docs-content ol {
    margin-bottom: 1rem;
    padding-left: 1.5rem;
    color: var(--text-secondary);
}

.docs-content li {
    margin-bottom: 0.375rem;
    line-height: 1.75;
}

.docs-content code {
    font-family: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
    font-size: 0.875em;
    background: var(--bg-code);
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    color: var(--accent);
}

.docs-content pre.code-block {
    margin-bottom: 1.5rem;
    padding: 1.25rem;
    background: var(--bg-code);
    border: 1px solid var(--border);
    border-radius: 0.75rem;
    overflow-x: auto;
    font-size: 0.8125rem;
    line-height: 1.7;
}

.docs-content pre.code-block code {
    background: none;
    padding: 0;
    color: var(--text-secondary);
}

.docs-content blockquote {
    margin: 1rem 0;
    padding: 0.75rem 1rem;
    border-left: 3px solid var(--accent);
    background: var(--surface-hover);
    border-radius: 0 0.5rem 0.5rem 0;
}

.docs-content blockquote p {
    margin-bottom: 0;
}

.docs-content table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1.5rem;
}

.docs-content th, .docs-content td {
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--border);
    text-align: left;
    font-size: 0.875rem;
}

.docs-content th {
    background: var(--bg-card);
    font-weight: 600;
    color: var(--text-primary);
}

.docs-content td {
    color: var(--text-secondary);
}

.docs-nav-bottom {
    display: flex;
    justify-content: space-between;
    margin-top: 3rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border);
    gap: 1rem;
}

.docs-nav-prev, .docs-nav-next {
    display: flex;
    flex-direction: column;
    padding: 0.75rem 1rem;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    text-decoration: none;
    transition: border-color 0.15s;
}

.docs-nav-prev:hover, .docs-nav-next:hover {
    border-color: var(--accent);
}

.docs-nav-next {
    text-align: right;
    margin-left: auto;
}

.docs-nav-label {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.docs-nav-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--accent);
}

.docs-not-found {
    text-align: center;
    padding: 4rem 0;
}

.docs-not-found h1 {
    font-size: 1.5rem;
    color: var(--text-primary);
}

.docs-not-found p {
    color: var(--text-muted);
    margin-top: 0.5rem;
}

.docs-not-found a {
    display: inline-block;
    margin-top: 1rem;
    color: var(--accent);
}

@media (max-width: 768px) {
    .docs-page {
        padding: 1.5rem;
    }
}
{% endapply %}
```

- [ ] **Step 5: Commit**

```bash
git add app/Components/TableOfContents/ app/Components/DocsPage/
git commit -m "feat: add DocsPage and TableOfContents components"
```

---

### Task 14: Docs Components — DocsSearch

**Files:**
- Create: `app/Components/DocsSearch/DocsSearch.php`
- Create: `app/Components/DocsSearch/DocsSearch.twig`

- [ ] **Step 1: Create DocsSearch component PHP**

`app/Components/DocsSearch/DocsSearch.php`:
```php
<?php

declare(strict_types=1);

namespace App\Components\DocsSearch;

use App\Markdown\FrontmatterExtractor;
use Preflow\Components\Component;

final class DocsSearch extends Component
{
    public string $query = '';
    /** @var array<int, array{title: string, path: string, excerpt: string}> */
    public array $results = [];
    public bool $searched = false;

    public function resolveState(): void
    {
        $this->query = $this->props['query'] ?? '';
    }

    public function actions(): array
    {
        return ['search'];
    }

    public function actionSearch(array $params = []): void
    {
        $this->query = trim($params['q'] ?? '');
        $this->searched = true;

        if ($this->query === '') {
            $this->results = [];
            return;
        }

        $basePath = $this->props['basePath'] ?? '';
        $index = $this->buildIndex($basePath);
        $needle = strtolower($this->query);

        $this->results = [];
        foreach ($index as $entry) {
            if (
                str_contains(strtolower($entry['title']), $needle)
                || str_contains(strtolower($entry['excerpt']), $needle)
            ) {
                $this->results[] = $entry;
            }

            if (count($this->results) >= 10) {
                break;
            }
        }
    }

    /**
     * @return array<int, array{title: string, path: string, excerpt: string}>
     */
    private function buildIndex(string $basePath): array
    {
        $cachePath = dirname($basePath) . '/storage/cache/docs-index.json';

        if (file_exists($cachePath)) {
            $cached = json_decode(file_get_contents($cachePath), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $index = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            $result = FrontmatterExtractor::extract($content);
            $relativePath = str_replace([$basePath . '/', '.md'], '', $file->getPathname());

            $body = strip_tags($result['body']);
            $excerpt = mb_substr($body, 0, 200);

            $index[] = [
                'title' => $result['meta']['title'] ?? ucfirst(basename($relativePath)),
                'path' => '/docs/' . $relativePath,
                'excerpt' => $excerpt,
            ];
        }

        if (!is_dir(dirname($cachePath))) {
            mkdir(dirname($cachePath), 0755, true);
        }
        file_put_contents($cachePath, json_encode($index));

        return $index;
    }
}
```

- [ ] **Step 2: Create DocsSearch template**

`app/Components/DocsSearch/DocsSearch.twig`:
```twig
<div class="docs-search">
    <input
        type="search"
        name="q"
        class="docs-search-input"
        placeholder="Search docs..."
        value="{{ query }}"
        {{ hd.get('search', componentClass, componentId, props) | raw }}
        hx-trigger="keyup changed delay:300ms"
        hx-include="this"
    >
    {% if searched and results|length > 0 %}
        <div class="docs-search-results">
            {% for result in results %}
                <a href="{{ result.path }}" class="docs-search-result">
                    <div class="docs-search-result-title">{{ result.title }}</div>
                    <div class="docs-search-result-excerpt">{{ result.excerpt }}</div>
                </a>
            {% endfor %}
        </div>
    {% elseif searched and query != '' %}
        <div class="docs-search-results">
            <div class="docs-search-empty">No results for "{{ query }}"</div>
        </div>
    {% endif %}
</div>

{% apply css %}
.docs-search {
    position: relative;
    margin-bottom: 1rem;
}

.docs-search-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    background: var(--bg-card);
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    color: var(--text-primary);
    font-size: 0.8125rem;
    font-family: inherit;
    outline: none;
    transition: border-color 0.15s;
}

.docs-search-input::placeholder {
    color: var(--text-muted);
}

.docs-search-input:focus {
    border-color: var(--accent);
}

.docs-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    margin-top: 0.25rem;
    background: var(--bg-primary);
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    max-height: 20rem;
    overflow-y: auto;
    z-index: 50;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
}

.docs-search-result {
    display: block;
    padding: 0.625rem 0.75rem;
    text-decoration: none;
    border-bottom: 1px solid var(--border);
    transition: background 0.1s;
}

.docs-search-result:last-child {
    border-bottom: none;
}

.docs-search-result:hover {
    background: var(--surface-hover);
}

.docs-search-result-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--text-primary);
}

.docs-search-result-excerpt {
    font-size: 0.75rem;
    color: var(--text-muted);
    margin-top: 0.125rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.docs-search-empty {
    padding: 0.75rem;
    text-align: center;
    color: var(--text-muted);
    font-size: 0.8125rem;
}
{% endapply %}
```

- [ ] **Step 3: Commit**

```bash
git add app/Components/DocsSearch/
git commit -m "feat: add DocsSearch component with HTMX-powered search"
```

---

### Task 15: Docs Route and Layout

**Files:**
- Create: `app/pages/docs/[...path].twig`

- [ ] **Step 1: Create the docs catch-all route**

`app/pages/docs/[...path].twig`:
```twig
{% extends "_layout.twig" %}

{% block title %}{{ docTitle ?? 'Documentation' }} — Preflow{% endblock %}
{% block description %}{{ docDescription ?? 'Preflow framework documentation' }}{% endblock %}

{% block content %}
{% set manifest = docsManifest %}
{% set basePath = docsBasePath %}
{% set cachePath = docsCachePath %}

<div class="docs-layout">
    <div class="docs-sidebar-wrapper">
        {{ component('DocsSearch', { basePath: basePath }) }}
        {{ component('DocsSidebar', { manifest: manifest, basePath: basePath, currentPath: path }) }}
    </div>

    {{ component('DocsPage', {
        docPath: path,
        basePath: basePath,
        manifest: manifest,
        cachePath: cachePath
    }) }}

    {{ component('TableOfContents', { headings: docsHeadings ?? [] }) }}
</div>
{% endblock %}

{% apply css %}
.docs-layout {
    display: flex;
    max-width: 80rem;
    margin: 0 auto;
    min-height: calc(100vh - 3.5rem);
}

.docs-sidebar-wrapper {
    width: 15rem;
    flex-shrink: 0;
    padding-top: 1rem;
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
}

.docs-sidebar-wrapper .docs-search {
    padding: 0 1rem;
}

@media (max-width: 768px) {
    .docs-layout {
        flex-direction: column;
    }

    .docs-sidebar-wrapper {
        width: 100%;
        border-right: none;
        border-bottom: 1px solid var(--border);
    }
}
{% endapply %}
```

Note: The template variables `docsManifest`, `docsBasePath`, `docsCachePath` need to be available as globals. Update `DocsServiceProvider` to expose them:

Update `app/Providers/DocsServiceProvider.php` — add a `boot()` method:
```php
public function boot(): void
{
    $engine = $this->container->get(\Preflow\View\TemplateEngineInterface::class);

    $engine->addGlobal('docsManifest', $this->container->get('docs.manifest'));
    $engine->addGlobal('docsBasePath', $this->container->get('docs.base_path'));
    $engine->addGlobal('docsCachePath', $this->container->get('docs.cache_path'));
}
```

The full updated `app/Providers/DocsServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Markdown\MarkdownParser;
use Preflow\Core\ServiceProvider;
use Preflow\View\TemplateEngineInterface;

final class DocsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(MarkdownParser::class, fn () => new MarkdownParser());

        $this->container->singleton('docs.manifest', function () {
            $path = $this->container->get('path.base') . '/docs/manifest.php';
            return file_exists($path) ? require $path : [];
        });

        $this->container->singleton('docs.base_path', function () {
            return $this->container->get('path.base') . '/docs';
        });

        $this->container->singleton('docs.cache_path', function () {
            return $this->container->get('path.base') . '/storage/cache/docs';
        });
    }

    public function boot(): void
    {
        $engine = $this->container->get(TemplateEngineInterface::class);

        $engine->addGlobal('docsManifest', $this->container->get('docs.manifest'));
        $engine->addGlobal('docsBasePath', $this->container->get('docs.base_path'));
        $engine->addGlobal('docsCachePath', $this->container->get('docs.cache_path'));
    }
}
```

- [ ] **Step 2: Create a test markdown file to verify the docs system works**

`docs/getting-started/installation.md`:
```markdown
---
title: Installation
description: How to install Preflow and create your first project
group: getting-started
order: 1
---

# Installation

Preflow requires PHP 8.4 or later.

## Create a New Project

The fastest way to start is with Composer's `create-project` command:

```bash
composer create-project preflow/skeleton myapp
cd myapp
```

This gives you a working application with:

- File-based routing in `app/pages/`
- Example components in `app/Components/`
- SQLite database in `storage/data/`
- Development server via CLI

## Start the Development Server

```bash
php preflow serve
```

Visit [http://localhost:8080](http://localhost:8080) in your browser.

## Requirements

- PHP 8.4+
- Composer 2.x
- SQLite extension (for the default data driver)
- mbstring extension
```

- [ ] **Step 3: Verify — start dev server and navigate to docs**

```bash
php preflow serve
```

Visit `http://localhost:8080/docs/getting-started/installation`. Verify: sidebar renders with groups, content shows the parsed markdown with syntax-highlighted code blocks, table of contents shows headings on the right. Test search by typing "install" in the search box.

- [ ] **Step 4: Commit**

```bash
git add app/pages/docs/ app/Providers/DocsServiceProvider.php docs/getting-started/installation.md
git commit -m "feat: add docs route with sidebar, TOC, search, and first doc page"
```

---

### Task 16: Documentation Content — Getting Started

**Files:**
- Create: `docs/getting-started/configuration.md`
- Create: `docs/getting-started/directory-structure.md`

- [ ] **Step 1: Create configuration.md**

`docs/getting-started/configuration.md`:
```markdown
---
title: Configuration
description: Application configuration and environment variables
group: getting-started
order: 2
---

# Configuration

Preflow uses PHP config files in the `config/` directory and environment variables via `.env`.

## Environment Variables

Copy `.env.example` to `.env` and adjust:

```bash
APP_NAME="My App"
APP_DEBUG=1
APP_KEY=change-this-to-a-random-32-char-string!
APP_TIMEZONE=UTC
APP_LOCALE=en
APP_ENGINE=twig
```

Generate a secure `APP_KEY`:

```bash
php preflow key:generate
```

## Config Files

All config files live in `config/` and return PHP arrays:

### app.php

```php
return [
    'name' => getenv('APP_NAME') ?: 'Preflow App',
    'debug' => (int) (getenv('APP_DEBUG') ?: 0),
    'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    'locale' => getenv('APP_LOCALE') ?: 'en',
    'key' => getenv('APP_KEY') ?: '',
    'engine' => getenv('APP_ENGINE') ?: 'twig',
];
```

### data.php

```php
return [
    'drivers' => [
        'sqlite' => [
            'driver' => \Preflow\Data\Driver\SqliteDriver::class,
            'path' => __DIR__ . '/../storage/data/app.sqlite',
        ],
    ],
    'default' => 'sqlite',
];
```

### middleware.php

Register global middleware. Framework middleware (session, CSRF, i18n) is auto-discovered.

### providers.php

Register service providers that boot with your application.

## Debug Modes

- `0` — Production: minimal errors, no dev panels
- `1` — Development: detailed errors, component inspector
- `2` — Verbose: forces dev panels on all components
```

- [ ] **Step 2: Create directory-structure.md**

`docs/getting-started/directory-structure.md`:
```markdown
---
title: Directory Structure
description: Understanding the Preflow project layout
group: getting-started
order: 3
---

# Directory Structure

A new Preflow project has this layout:

```
myapp/
├── app/
│   ├── Components/      # Reusable UI components
│   ├── Controllers/     # Attribute-routed controllers
│   ├── Models/          # Data models
│   ├── Providers/       # Service providers
│   ├── Middleware/       # Custom middleware
│   └── pages/           # File-based routes (templates)
├── config/              # Configuration files
├── lang/                # Translation files
├── migrations/          # Database migrations
├── public/              # Web root
│   └── index.php        # Entry point
├── storage/
│   ├── cache/           # Framework cache
│   ├── data/            # SQLite database, JSON files
│   └── logs/            # Application logs
└── tests/               # PHPUnit tests
```

## Key Directories

### app/Components/

Each component is a directory containing a PHP class and a template:

```
ExampleCard/
├── ExampleCard.php      # Component logic
└── ExampleCard.twig     # Component template
```

Components are auto-discovered — drop the directory in and use it.

### app/pages/

File-based routing maps the directory structure to URLs:

- `index.twig` → `/`
- `about.twig` → `/about`
- `blog/index.twig` → `/blog`
- `blog/[slug].twig` → `/blog/{slug}`
- `docs/[...path].twig` → `/docs/{path}` (catch-all)

Files prefixed with `_` are excluded from routing (`_layout.twig`, `_partial.twig`).

### app/Controllers/

For API endpoints and form handlers. Use PHP attributes for routing:

```php
#[Route('/api')]
final class ApiController
{
    #[Get('/status')]
    public function status(): Response { ... }
}
```

### public/

The web root. Only `index.php` and static assets go here. The entry point is three lines:

```php
require __DIR__ . '/../vendor/autoload.php';
$app = Preflow\Core\Application::create(__DIR__ . '/..');
$app->boot();
$app->run();
```
```

- [ ] **Step 3: Commit**

```bash
git add docs/getting-started/
git commit -m "docs: add Getting Started guides (configuration, directory structure)"
```

---

### Task 17: Documentation Content — Guides

**Files:**
- Create: `docs/guides/routing.md`
- Create: `docs/guides/components.md`
- Create: `docs/guides/data.md`
- Create: `docs/guides/authentication.md`
- Create: `docs/guides/internationalization.md`
- Create: `docs/guides/testing.md`

Content for each guide should be written as task-oriented documentation drawing from the corresponding package READMEs. Each file follows the frontmatter format:

```yaml
---
title: [Guide Title]
description: [One-line description]
group: guides
order: [1-6]
---
```

- [ ] **Step 1: Create routing.md**

Write the routing guide covering:
- File-based routing conventions (directory → URL mapping, dynamic segments `[param]`, catch-all `[...param]`)
- Attribute-based routing (`#[Route]`, `#[Get]`, `#[Post]`, `#[Middleware]`)
- Layout files and the underscore prefix convention
- Route priority (static > dynamic > catch-all)
- Route caching (`php preflow cache:clear`)

Source: `packages/routing/README.md` (131 lines)

- [ ] **Step 2: Create components.md**

Write the components guide covering:
- Component structure (PHP class + template directory)
- Lifecycle (`resolveState()`, public properties → template variables)
- Props passing (`{{ component('Name', { key: value }) }}`)
- Actions and HTMX integration (`actions()`, `actionName()`, `hd.post()`)
- Co-located CSS/JS (`{% apply css %}`, `{% apply js %}`)
- Error boundaries and fallbacks

Source: `packages/components/README.md` (99 lines), `packages/twig/README.md` (94 lines)

- [ ] **Step 3: Create data.md**

Write the data guide covering:
- Model definition (`#[Entity]`, `#[Id]`, `#[Field]`)
- DataManager API (`find()`, `query()`, `save()`, `delete()`)
- QueryBuilder (where, orderBy, limit, search, pagination)
- Storage drivers (SQLite, JSON, MySQL)
- Migrations and schema builder

Source: `packages/data/README.md` (165 lines)

- [ ] **Step 4: Create authentication.md**

Write the authentication guide covering:
- User model and `Authenticatable` interface
- Guards (SessionGuard, TokenGuard)
- AuthMiddleware and GuestMiddleware
- Login/logout flow with session fixation prevention
- Template functions (`auth_check()`, `auth_user()`, `csrf_token()`)
- Password hashing

Source: `packages/auth/README.md` (211 lines)

- [ ] **Step 5: Create internationalization.md**

Write the i18n guide covering:
- Translation files and directory structure
- Translator API and template functions (`t()`, `tc()`)
- Pluralization
- Locale detection (URL prefix, cookie, Accept-Language)
- Configuration

Source: `packages/i18n/README.md` (108 lines)

- [ ] **Step 6: Create testing.md**

Write the testing guide covering:
- ComponentTestCase and rendering tests
- RouteTestCase with TestResponse assertions
- DataTestCase with in-memory SQLite
- Running tests with PHPUnit

Source: `packages/testing/README.md` (129 lines)

- [ ] **Step 7: Commit**

```bash
git add docs/guides/
git commit -m "docs: add task-oriented guides (routing, components, data, auth, i18n, testing)"
```

---

### Task 18: Documentation Content — Package Reference

**Files:**
- Create: `docs/packages/core.md` through `docs/packages/skeleton.md` (13 files)

Each package reference page follows the same pattern:

```yaml
---
title: [Package Name]
description: [One-line from package composer.json description]
group: packages
order: [1-13]
---
```

- [ ] **Step 1: Create all 13 package reference docs**

Adapt each package's README.md into the docs format. The content is already written — reformat with frontmatter, ensure code examples are in fenced blocks, and adjust any relative links to absolute paths.

Package order and sources:
1. `core.md` ← `packages/core/README.md` (171 lines)
2. `routing.md` ← `packages/routing/README.md` (131 lines)
3. `view.md` ← `packages/view/README.md` (104 lines)
4. `components.md` ← `packages/components/README.md` (99 lines)
5. `twig.md` ← `packages/twig/README.md` (94 lines)
6. `blade.md` ← `packages/blade/README.md` (131 lines)
7. `data.md` ← `packages/data/README.md` (165 lines)
8. `htmx.md` ← `packages/htmx/README.md` (182 lines)
9. `i18n.md` ← `packages/i18n/README.md` (108 lines)
10. `auth.md` ← `packages/auth/README.md` (211 lines)
11. `devtools.md` ← `packages/devtools/README.md` (102 lines)
12. `testing.md` ← `packages/testing/README.md` (129 lines)
13. `skeleton.md` ← `packages/skeleton/README.md` (206 lines)

- [ ] **Step 2: Commit**

```bash
git add docs/packages/
git commit -m "docs: add package reference pages (13 packages)"
```

---

### Task 19: Deployer Recipe

**Files:**
- Create: `deploy.php`

- [ ] **Step 1: Create deploy.php**

`deploy.php`:
```php
<?php

namespace Deployer;

require 'recipe/common.php';

set('application', 'preflow-website');
set('repository', 'git@github.com:getpreflow/preflow-website.git');
set('keep_releases', 5);

host('production')
    ->set('hostname', 'your-server.com')
    ->set('remote_user', 'deploy')
    ->set('deploy_path', '/var/www/preflow-website');

set('shared_dirs', [
    'storage/cache',
    'storage/logs',
]);

set('writable_dirs', [
    'storage/cache',
    'storage/logs',
]);

task('deploy:env', function () {
    if (!test('[ -f {{deploy_path}}/shared/.env ]')) {
        upload('.env.example', '{{deploy_path}}/shared/.env');
        warning('Uploaded .env.example as .env — update it on the server.');
    }
    run('ln -sfn {{deploy_path}}/shared/.env {{release_path}}/.env');
});

task('deploy:cache:clear', function () {
    run('rm -rf {{release_path}}/storage/cache/docs/*');
    run('rm -f {{release_path}}/storage/cache/docs-index.json');
});

task('deploy', [
    'deploy:prepare',
    'deploy:vendors',
    'deploy:env',
    'deploy:cache:clear',
    'deploy:symlink',
    'deploy:cleanup',
    'deploy:success',
]);

after('deploy:failed', 'deploy:unlock');
```

- [ ] **Step 2: Verify deployer loads**

```bash
./vendor/bin/dep list
```

Expected: Deployer command list shown without errors.

- [ ] **Step 3: Commit**

```bash
git add deploy.php
git commit -m "feat: add Deployer recipe for shared hosting deployment"
```

---

### Task 20: Final Integration Test and Polish

- [ ] **Step 1: Run the full test suite**

```bash
./vendor/bin/phpunit --testdox
```

Expected: All tests pass (FrontmatterExtractor: 3, SyntaxHighlightExtension: 2, MarkdownParser: 3).

- [ ] **Step 2: Start dev server and do a full walkthrough**

```bash
php preflow serve
```

Verify:
1. Landing page: Hero, features, quickstart, code examples, architecture, packages, footer
2. Theme toggle works (dark ↔ light), persists across page loads
3. `/docs/getting-started/installation` renders with sidebar, content, TOC
4. Sidebar groups collapse/expand
5. Prev/Next navigation works at bottom of doc pages
6. Search returns results
7. All code blocks have syntax highlighting
8. Mobile responsive (check 375px width)

- [ ] **Step 3: Fix any issues found during walkthrough**

Address any rendering, layout, or navigation issues discovered.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix: polish from integration walkthrough"
```

- [ ] **Step 5: Create GitHub repository and push**

```bash
gh repo create getpreflow/preflow-website --public --source=. --push
```
