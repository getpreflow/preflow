# Preflow DevTools

CLI commands for Preflow development. Requires the `preflow` entry point in your project root (provided by `preflow/skeleton`) or the package binary at `vendor/bin/preflow`.

## Installation

```bash
composer require preflow/devtools --dev
```

## Commands

### `serve` — start the dev server

Starts PHP's built-in server rooted at `public/`.

```
php preflow serve
# Preflow dev server started on http://localhost:8080
# Press Ctrl+C to stop.

php preflow serve 0.0.0.0 9000
# Binds to 0.0.0.0:9000
```

### `migrate` — run pending migrations

Reads `config/data.php` for the SQLite path, then runs any unexecuted files from `migrations/`.

```
php preflow migrate
# Running migrations...
# 2 pending migration(s).
# Done.
```

### `make:component Name` — scaffold a component

Creates `app/Components/Name/Name.php` and `app/Components/Name/Name.twig`.

```
php preflow make:component ProductCard
# Component created: app/Components/ProductCard/
#   - ProductCard.php
#   - ProductCard.twig
```

The generated PHP class extends `Component` with empty `resolveState()` and `actions()`. The Twig template includes `{% apply css %}` and `{% apply js %}` blocks.

### `make:model Name` — scaffold a model

Creates `app/Models/Name.php` with `#[Entity]`, `#[Id]`, `#[Field]`, and `#[Timestamps]` attributes. The table name is derived by snake-casing the class name and appending `s`.

```
php preflow make:model BlogPost
# Model created: app/Models/BlogPost.php
# (maps to table: blog_posts)
```

### `make:controller Name` — scaffold a controller

Creates `app/Controllers/NameController.php` with a `#[Route]` class attribute and a `#[Get]` index action. Appends `Controller` to the name if not already present.

```
php preflow make:controller Article
# Controller created: app/Controllers/ArticleController.php
# (route prefix: /api/article)
```

### `make:migration name` — create a migration file

Creates a timestamped file in `migrations/` with empty `up()` and `down()` stubs.

```
php preflow make:migration create_comments
# Migration created: migrations/2026_04_10_143012_create_comments.php
```

### `routes:list` — print the route table

Scans `app/pages/` using the file-based router and prints all registered routes.

```
php preflow routes:list
# Registered Routes
# ──────────────────────────────────────────────────────────────────────
# Method  Pattern                            Mode        Handler
# ──────────────────────────────────────────────────────────────────────
# GET     /                                  page        pages/index.twig
# GET     /about                             page        pages/about.twig
# ──────────────────────────────────────────────────────────────────────
```

### `cache:clear` — clear framework caches

Deletes all files under `storage/cache/` recursively.

```
php preflow cache:clear
# Cleared 14 cached file(s).
```
