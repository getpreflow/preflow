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

### `migrate` — run pending migrations and post-migrate steps

Boots the application (so packages register their migration paths and steps), then:

1. runs every pending file from `migrations/` and from the paths packages registered
   (core's `preflow_throttle` table among them) on a SQL storage;
2. runs every registered post-migrate step, such as Folio's table sync for SQL-stored
   content types.

```
php preflow migrate
# 2 pending migration(s).
# [folio.tables] created table post
# Done.
php preflow migrate --pretend          # list the pending migrations, change nothing
php preflow migrate --storage=sqlite   # the storage to migrate (default: sqlite)
```

- Steps run on **every** run, not only when a migration was pending, so they must be
  idempotent. Folio's step is SQLite-only for now (a Folio type on MySQL makes it throw),
  and creates and adds only; it never drops or renames.
- `--pretend` skips the steps. The command exits 1 with `Error: ...` on stderr when it fails.
- A site without SQL storage (JSON only, or no `config/data.php`) prints
  `Nothing to migrate: no SQL storage configured.` and exits 0, so a shared deploy step
  can always run it. An explicit `--storage=<key>` that is unknown or not SQL still exits 1.

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

### `cache:list` — list registered caches

Shows every cache registered with the core cache registry: key, label,
number of entries and size.

```
php preflow cache:list
# Key        Label                     Entries  Size
# data.json  JSON storage query cache  12       3.1 KB
```

### `cache:clear [KEY]` — clear framework caches

`cache:clear <key>` clears one registered cache; `cache:clear --all` clears
every registered cache. An unknown key exits with 1 and lists the known keys.
Files outside the registered caches are never deleted. The bare form
(`cache:clear` without arguments) still clears all caches but prints a note;
it will require `--all` in a later release. An unknown option (anything starting
with `--` other than `--all`) exits with 1 and clears nothing.

`cache:list` and `cache:clear` boot the application to read the registry, so
they need what the app needs to boot (`.env`, config, a reachable database for
SQL drivers). When the boot fails they print the error and exit with 1.

```
php preflow cache:clear --all
# Cleared data.json: 12 file(s), 3.1 KB.
```

### `auth:user [PREFIX]` — set a login in `.env`

Asks for an email and a password (hidden, twice, at least 8 characters) and
writes `<PREFIX>_EMAIL` and `<PREFIX>_PASSWORD_HASH` to `.env`, replacing the
lines when they exist. The prefix defaults to `FOLIO_ADMIN`; the password and
its hash are never printed. `config/auth.php` reads the two values, e.g. for a
`ConfigUserProvider` user.

```
php preflow auth:user
php preflow auth:user FOLIO_EDITOR --email=editor@example.com
# Set FOLIO_EDITOR_EMAIL=editor@example.com and FOLIO_EDITOR_PASSWORD_HASH in .env.
```

## Commands from packages and the app

A package brings its own console commands by listing the classes in its `composer.json`
(constructed without arguments, so they implement `Command\CommandInterface`):

```json
"extra": { "preflow": { "commands": ["Vendor\\Pkg\\Console\\PruneCommand"] } }
```

`Console` reads them from `vendor/composer/installed.json`, so the site needs no
`config/commands.php` line (e.g. `members:prune` from `preflow/members`). A site's own
`config/commands.php` is registered after and wins on a name clash. A class that is missing or
not a command is skipped with a warning on stderr.
