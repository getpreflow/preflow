# Folio Demo

A small, runnable app that boots the [Folio](../../packages/folio) CMS admin so
you can see it in a browser. It exists to **demonstrate** the admin — it is not a
project template. To start a real project, use the
[skeleton](../../packages/skeleton).

It ships two content types (`Pages`, `Articles`) and the Folio admin mounted at
`/folio`.

## Run it

From the repo root:

```bash
# 1. Seed a few Pages and Articles (idempotent — clears and reseeds)
php examples/folio-demo/seed.php

# 2. Serve it (index.php doubles as the dev-server router)
php -S 127.0.0.1:8000 examples/folio-demo/public/index.php
```

Then open:

- `http://127.0.0.1:8000/folio` — dashboard (content-type cards with counts)
- `http://127.0.0.1:8000/folio/page` — list view (table, edit/delete)
- `http://127.0.0.1:8000/folio/page/new` — create form

Toggle dark mode with the **Theme** button in the sidebar (it follows your OS by
default and remembers your choice).

## How it works

- **Zero install.** It boots off the monorepo's root `vendor/` — there is no
  separate `composer install` for this directory. `public/index.php` requires the
  root autoloader and calls `$app->run()`, exactly like a real Preflow app.
- **JSON storage.** Records are written to `storage/data/` (git-ignored). Delete
  that directory (or re-run `seed.php`) to reset.
- **Configuration** lives in `config/` — `providers.php` registers
  `FolioServiceProvider`, `folio.php` sets the admin path, and `models/*.json`
  define the content types.

## Tests

A smoke test (`tests/FolioDemoTest.php`) boots this app and asserts the admin
serves, so the example can't silently rot:

```bash
vendor/bin/phpunit examples/folio-demo/tests
```
