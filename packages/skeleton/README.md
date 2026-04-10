# Preflow Skeleton

Starter template for new Preflow applications.

## Create a project

```bash
composer create-project preflow/skeleton myapp
cd myapp
cp .env.example .env
php preflow migrate
php preflow serve
```

Open `http://localhost:8080`.

## What's included

### Application code

| Path | Description |
|---|---|
| `app/Components/ExampleCard/` | Working component with props, state, HTMX counter action, co-located CSS and JS |
| `app/Controllers/Api/HealthController.php` | `GET /api/health` — returns JSON status |
| `app/Models/Post.php` | Typed model with `#[Entity]`, `#[Id]`, `#[Field]`, `#[Timestamps]` attributes |
| `app/pages/` | File-based routes: `_layout.twig`, `index.twig`, `about.twig`, `_error.twig` |

### Translations

Bilingual out of the box:

```
lang/
  en/app.php
  de/app.php
```

`lang/en/app.php`:

```php
return [
    'name'        => 'Preflow App',
    'welcome'     => 'Welcome to :name!',
    'description' => 'A modern PHP framework for component-based web development.',
];
```

### Migrations

`migrations/2026_04_11_create_posts.php` — creates the `posts` table with UUID primary key, `title`, `slug`, `body`, `status`, and timestamps.

Run with:

```bash
php preflow migrate
```

### Configuration

| File | Controls |
|---|---|
| `config/app.php` | App name, environment, debug flag |
| `config/data.php` | SQLite path, JSON storage directory |
| `config/i18n.php` | Default locale, available locales, URL strategy |
| `config/middleware.php` | Middleware stack |
| `config/providers.php` | Service provider registration |

### Dev tooling

- `preflow` — CLI entry point (wraps `vendor/bin/preflow`)
- `phpunit.xml` — PHPUnit configuration pointed at `tests/`
- `.env.example` — environment variable template

## Project structure

```
myapp/
├── app/
│   ├── Components/ExampleCard/
│   ├── Controllers/Api/HealthController.php
│   ├── Models/Post.php
│   ├── Providers/
│   └── pages/
├── config/
├── lang/en/  lang/de/
├── migrations/
├── public/index.php
├── storage/
├── tests/
├── .env
└── preflow
```
