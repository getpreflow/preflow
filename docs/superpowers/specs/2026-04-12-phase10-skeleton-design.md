# Phase 10: Skeleton — Design Spec

**Date:** 2026-04-12
**Status:** Approved
**Package affected:** `preflow/skeleton`, `preflow/devtools`

## Overview

Make `composer create-project preflow/skeleton myapp` a zero-friction experience: three commands to a fully working demo app with auth, blog, components, i18n, and HTMX. Add a LocaleSwitcher component, session-persisted counter demo, APP_KEY generation, post-install automation, Apache config, and a polished README.

## Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Primary goal | Polish create-project + add LocaleSwitcher + session counter | Skeleton content is already rich; focus on seamless first-run |
| Post-install automation | Full: .env + key + migrate + seed | Zero manual steps → working demo immediately |
| APP_KEY generation | DevTools console command | Callable from post-install script AND manually |
| LocaleSwitcher | Component with URL prefix links | Works with existing prefix strategy, no server-side logic |
| Session counter | ExampleCard reads/writes session | Demonstrates sessions without auth |
| Web server config | .htaccess for Apache, docs for nginx | .htaccess covers majority of setups |
| make:* scaffolding | Deferred | Patterns still settling, files serve as templates |

---

## 1. Post-create-project Automation

### Composer scripts

In `packages/skeleton/composer.json`, add:

```json
"scripts": {
    "post-create-project-cmd": [
        "@php -r \"copy('.env.example', '.env');\"",
        "@php preflow key:generate",
        "@php preflow migrate",
        "@php preflow seed"
    ]
}
```

After `composer create-project preflow/skeleton myapp`:
1. `.env` copied from `.env.example`
2. Random APP_KEY generated and written to `.env`
3. SQLite database created with users, user_tokens, and posts tables
4. Demo blog posts seeded

User runs `php preflow serve` and sees a working app.

### `key:generate` command

New command in `preflow/devtools`. Reads `.env` file, replaces the `APP_KEY=...` line with `APP_KEY=<random 32-char hex string>` (via `bin2hex(random_bytes(16))`). Writes back to `.env`.

If `.env` doesn't exist, prints an error message and exits with code 1.

The `Console` class in DevTools registers this command so `php preflow key:generate` works.

---

## 2. Session-persisted Counter (ExampleCard)

The ExampleCard component already has an `increment` HTMX action. Currently the count resets on page reload.

### Changes

- `resolveState()`: Read count from session — `$session->get('example_counter', 0)` — instead of initializing to 0.
- `actionIncrement()`: Read current count from session, increment, write back via `$session->set('example_counter', $count + 1)`. Return the new count.
- Session access: `SessionInterface` is registered in the container by `bootSession()`. The component gets it via constructor injection or from the request in action methods (`$request->getAttribute(SessionInterface::class)`).

### What this demonstrates

- Sessions work without authentication (no login required)
- Component actions can read/write server-side state
- HTMX partial updates preserve state across page navigations
- Different browser sessions get independent counters

---

## 3. LocaleSwitcher Component

### Files

```
app/Components/LocaleSwitcher/
├── LocaleSwitcher.php
└── LocaleSwitcher.twig
```

### PHP class

Constructor-injected dependencies:
- `Translator` — for `getLocale()` and to derive available locales from config
- `RequestContext` — for current request path

`resolveState()` returns:
- `locales`: array of available locales from `config/i18n.php`
- `current`: current active locale
- `currentPath`: request path with locale prefix stripped (for building switch links)

No actions — locale switching is pure navigation (link clicks).

### Template

Renders a compact button group. One link per locale. Each link points to the current path with the locale prefix swapped:

- Current path `/de/blog`, click "EN" → `/en/blog`
- Current path `/blog` (default locale), click "DE" → `/de/blog`

Active locale gets a visually distinct style (e.g. bolder, different background). Inline CSS via `{% apply css %}`.

### Layout integration

Add `{{ component('LocaleSwitcher') }}` to `_layout.twig` in the header area, next to the Navigation component.

### Middleware config cleanup

Remove the misleading commented-out `LocaleMiddleware` reference from `config/middleware.php`. The i18n middleware is auto-discovered by `Application::bootI18n()` when `preflow/i18n` is installed — the comment suggests manual registration is needed when it isn't.

---

## 4. Apache .htaccess

### `public/.htaccess`

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
```

Routes all requests that don't match a physical file or directory to `index.php`. Standard Apache rewrite configuration.

---

## 5. README Overhaul

### Quickstart

```bash
composer create-project preflow/skeleton myapp
cd myapp
php preflow serve
```

Three commands. Post-install handles everything else.

### Project structure guide

```
app/
├── Components/    → Reusable UI components (PHP + template + CSS/JS)
├── Controllers/   → API and form controllers (#[Route] attributes)
├── Models/        → Data models (#[Entity] attributes)
├── Providers/     → Service providers
├── Seeds/         → Demo data seeders
└── pages/         → File-based routes (Twig templates)
config/            → Framework configuration
lang/              → Translation files (en/, de/)
migrations/        → Database schema
public/            → Web root (index.php, .htaccess)
storage/           → SQLite database, cache, logs
tests/             → PHPUnit tests
```

### Feature sections

Brief code-example sections for each feature:
- **Routing** — File-based (`app/pages/`) and attribute-based (`#[Route]`, `#[Get]`, `#[Post]`)
- **Components** — ExampleCard pattern (PHP class + template + inline CSS/JS + HTMX actions)
- **Authentication** — Login/register flows, protecting routes with `#[Middleware(AuthMiddleware::class)]`
- **Internationalization** — Translation files, `t()` function, LocaleSwitcher, URL prefix strategy
- **Data layer** — Models with `#[Entity]`/`#[Field]`, queries via DataManager
- **HTMX** — Component actions, session-persisted counter
- **Testing** — PHPUnit setup, `actingAs()` helper

### Web server section

- **Development:** `php preflow serve` (built-in PHP server)
- **Apache:** Point document root to `public/`, ensure `mod_rewrite` enabled
- **Nginx:** Copy-paste server block with `try_files $uri $uri/ /index.php?$query_string`

### Cleanup

- Remove commented-out LocaleMiddleware from `config/middleware.php`
- Update `_error.twig` to show the actual error status code and message instead of hardcoded "404"

---

## 6. Testing

### New tests

**LocaleSwitcher component:**
- Renders links for all available locales
- Highlights the active locale
- Generates correct URL paths with locale prefix

**ExampleCard session counter:**
- Counter starts at 0 from session
- Increment action writes to session
- Value persists across calls

**KeyGenerateCommand:**
- Generates key and writes to .env
- Replaces existing APP_KEY value
- Errors gracefully if .env missing

### Existing tests unchanged

`ExampleTest` and `NavigationActiveStateTest` remain as-is.
