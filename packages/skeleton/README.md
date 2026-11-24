# Preflow skeleton

An empty [Preflow](https://preflow.dev) app: one home page in English and German, and nothing else to delete.

```bash
composer create-project preflow/skeleton mysite
cd mysite
php preflow serve
```

Open http://localhost:8080. Create-project made `.env`, an app key and the database for you.

## What is here

```
app/Providers/    AppServiceProvider: bind your own services
app/pages/        file-based pages: _layout.twig, index.twig, _error.twig
config/           app, data (SQLite), i18n (en, de), security, csrf, htmx, middleware, page cache, seo
lang/             translations: en/app.php, de/app.php
migrations/       your database changes (empty)
public/           the web root
tests/            PHPUnit: HomePageTest
```

There are no models, no accounts and no demo data. Run the tests with `composer test`.

## The five commands you need first

| Command | What it does |
|---|---|
| `php preflow serve` | Start the development server on http://localhost:8080. |
| `php preflow migrate` | Run pending migrations (create-project ran it once). |
| `php preflow make:model Name` | Scaffold a model. |
| `php preflow routes:list` | Print the routes, file-based pages included. |
| `composer test` | Run the app's tests. |

`php preflow` alone lists every command.

## Add a page

Create `app/pages/about.twig`. It answers at `/about` (and `/de/about`), inside `_layout.twig`.
There is nothing to register.

## Add Folio, the admin

```bash
composer require preflow/folio preflow/rbac
php preflow folio:install
```

`folio:install` wires the admin into this app, runs the migrations and asks for the first admin's
e-mail address and password. It adds lines to your config files and never rewrites what is there;
run it again at any time and it only finishes what is missing. The admin is then at `/folio`.
`php preflow folio:install --dry-run` shows what it would do first. The Folio README has the
options and the manual steps.
