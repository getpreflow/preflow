---
name: folio-demo
description: Launch and drive the Folio CMS admin demo in a browser (examples/folio-demo). Use when asked to run the Folio demo, show/screenshot the Folio admin, or see the admin UI live.
---

# Run the Folio admin demo

The runnable demo lives at `examples/folio-demo/`. It boots off the monorepo's
root `vendor/` (no separate `composer install`) and mounts the Folio admin at
`/folio`. Run everything from the repo root.

## Launch

```bash
# Seed demo Pages + Articles (idempotent — safe to re-run; resets storage)
php examples/folio-demo/seed.php

# Serve. index.php doubles as the dev-server router, so all routes reach the app.
php -S 127.0.0.1:8000 examples/folio-demo/public/index.php
```

Routes:
- `http://127.0.0.1:8000/folio` — dashboard (content-type cards + counts)
- `http://127.0.0.1:8000/folio/page` — list (table, edit/delete)
- `http://127.0.0.1:8000/folio/page/new` — create form

## Smoke-check without a browser

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/folio
curl -s -D - -o /dev/null http://127.0.0.1:8000/folio/_assets/admin.css | grep -i 'content-type'
```

## Screenshot (and verify dark mode)

`playwright` (Homebrew) can emulate the OS color scheme — the cleanest way to
capture dark mode without injecting localStorage:

```bash
PW=$(command -v playwright)
"$PW" screenshot --viewport-size=1440,900 http://127.0.0.1:8000/folio /tmp/folio-light.png
"$PW" screenshot --viewport-size=1440,900 --color-scheme=dark http://127.0.0.1:8000/folio /tmp/folio-dark.png
```

**Look at the screenshots** — a blank frame means it failed to launch (check the
server output). After capturing, stop the server:

```bash
pkill -f "php -S 127.0.0.1:8000"
```

## Notes

- The login screen (`@folio/admin/login.twig`) is styled but has no route yet
  (auth is unwired). To preview it, render the template via the booted engine
  (`$app->container()->get(TemplateEngineInterface::class)->render('@folio/admin/login.twig', [])`).
- `$app->run()` needs `nyholm/psr7-server` (in the root dev deps). If it errors
  about that, run `composer install` at the repo root.
- This is a demo, not a project template — for a real app use
  `packages/skeleton`.
