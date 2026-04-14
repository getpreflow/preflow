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

### Blog Admin

The skeleton includes a full blog admin at `/admin` (requires login). Create, edit, and delete posts through a form-based interface.

Default admin credentials:

- **Email:** admin@preflow.dev
- **Password:** password

The admin demonstrates the hybrid pattern: `PostForm` component handles form UI with co-located CSS, `BlogAdminController` handles CRUD logic with redirects and flash messages.

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

### Asset helpers

`AppServiceProvider` registers `asset_url()` and `img_url()` template functions. Both resolve paths relative to the `public/` directory.

```twig
<link rel="stylesheet" href="{{ asset_url('css/app.css') }}">
<img src="{{ img_url('logo.png') }}" alt="Logo">
```

`img_url()` is a convenience wrapper that prepends `images/` automatically. Both functions are commented-out examples in `AppServiceProvider` — uncomment and adjust the base path for your deployment.

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
