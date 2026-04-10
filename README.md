# Preflow

A modern PHP 8.5+ framework for component-based web development. HTML over the wire.

## Philosophy

Browsers handle HTML best. Instead of shipping JSON to the client and hydrating with JavaScript frameworks, Preflow renders HTML on the server and uses hypermedia for interactivity.

- **Components are the primitive** — co-located PHP logic + Twig template + inline CSS/JS
- **Zero external asset requests** — all CSS/JS inline in the HTML, deduplicated by hash, minified in production
- **Hypermedia-driven** — HTMX by default, abstracted behind a driver interface
- **Security first** — HMAC-signed component tokens, CSP nonces, component-level auth guards
- **PHP 8.5+ only** — attributes for DI, routing, models. No legacy patterns.

## Quick Start

```bash
composer create-project preflow/skeleton myapp
cd myapp
cp .env.example .env
php preflow migrate
php preflow serve
```

Open `http://localhost:8080` — you'll see a working component with a counter.

## Packages

| Package | Description |
|---|---|
| [`preflow/core`](packages/core) | DI container, config, middleware pipeline, error handler, dual-mode kernel |
| [`preflow/routing`](packages/routing) | File-based + attribute-based hybrid router with caching |
| [`preflow/view`](packages/view) | Template engine interface, Twig adapter, asset pipeline with CSP nonces |
| [`preflow/components`](packages/components) | Component base class, lifecycle, error boundaries |
| [`preflow/data`](packages/data) | Storage drivers (SQLite, JSON), typed models, migrations |
| [`preflow/htmx`](packages/htmx) | Hypermedia driver, HTMX implementation, signed component tokens |
| [`preflow/i18n`](packages/i18n) | Translations, pluralization, locale detection middleware |
| [`preflow/testing`](packages/testing) | Test utilities for components, routes, data |
| [`preflow/devtools`](packages/devtools) | CLI commands (serve, migrate, make:component, etc.) |
| [`preflow/skeleton`](packages/skeleton) | Project starter template |

## Architecture

Preflow uses a **dual-mode kernel**:

- **Component Mode** — file-based routes (`app/pages/`) render page templates with embedded components
- **Action Mode** — attribute-routed controllers (`#[Get('/api/posts')]`) handle API requests

Both modes share the same DI container, middleware pipeline, data layer, and error handling.

```
Request → Middleware Pipeline → Kernel
    ├─ Component Mode → Page template → Component tree → Inline CSS/JS → HTML response
    └─ Action Mode → Controller method → JSON/HTML response
```

## Components

A component is a PHP class + a co-located Twig template:

```
app/Components/GameCard/
├── GameCard.php
└── GameCard.twig
```

```php
final class GameCard extends Component
{
    public string $title = '';

    public function resolveState(): void
    {
        $this->title = $this->props['title'] ?? 'Untitled';
    }

    public function actions(): array
    {
        return ['refresh'];
    }

    public function actionRefresh(): void
    {
        $this->title = 'Refreshed!';
    }
}
```

```twig
<div class="game-card">
    <h2>{{ title }}</h2>
    <button {{ hd.post('refresh', ...) | raw }}>Refresh</button>
</div>

{% apply css %}
.game-card { padding: 1rem; border-radius: 0.5rem; }
{% endapply %}
```

CSS and JS are registered inline in the HTML document, deduplicated by content hash. No external requests.

## Routing

**File-based** (Component Mode):
```
app/pages/
├── index.twig           → GET /
├── about.twig           → GET /about
├── blog/
│   ├── index.twig       → GET /blog
│   └── [slug].twig      → GET /blog/{slug}
```

**Attribute-based** (Action Mode):
```php
#[Route('/api/v1/posts')]
final class PostController
{
    #[Get('/')]
    public function index(): JsonResponse { ... }

    #[Get('/{uuid}')]
    public function show(string $uuid): JsonResponse { ... }
}
```

## Data Layer

Multi-storage: different models can use different backends in the same application.

```php
#[Entity(table: 'users', storage: 'sqlite')]
final class User extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field(searchable: true)]
    public string $name = '';

    #[Timestamps]
    public ?\DateTimeImmutable $createdAt = null;
}
```

```php
$users = $data->query(User::class)
    ->where('status', 'active')
    ->orderBy('name')
    ->paginate(perPage: 20, currentPage: 1);
```

## i18n

First-class, not an afterthought:

```twig
{{ t('blog.title') }}
{{ t('blog.post_count', { count: total }, total) }}
```

Locale detection: URL prefix → cookie → Accept-Language header → default.

## Development

```bash
git clone git@github.com:getpreflow/preflow.git
cd preflow
composer install
./vendor/bin/phpunit
```

### CLI Commands

```bash
php preflow serve                # Dev server
php preflow migrate              # Run migrations
php preflow make:component Name  # Scaffold component
php preflow make:model Name      # Scaffold model
php preflow make:controller Name # Scaffold controller
php preflow make:migration name  # Create migration
php preflow routes:list          # List routes
php preflow cache:clear          # Clear caches
```

## Requirements

- PHP 8.4+
- ext-pdo, ext-pdo_sqlite
- ext-sodium

## License

MIT
