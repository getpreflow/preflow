# Preflow skeleton

An empty [Preflow](https://getpreflow.dev) app: one home page in English and German, and nothing else to delete.

```bash
composer create-project preflow/skeleton mysite
cd mysite
php preflow serve
```

Open http://localhost:8080. Create-project made `.env`, an app key and the database for you.

## Next steps

Add a page: create `app/pages/about.twig`. It answers at `/about` (and `/de/about`).

Add an admin:

```bash
composer require preflow/folio preflow/rbac
php preflow folio:install
```

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
