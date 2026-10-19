# preflow/folio

Folio is a drop-in CMS for Preflow. Require it into any Preflow app and you get a
shipped admin (mounted at a configurable path), content types defined as JSON, and
slug-based frontend rendering — without touching the framework.

## Installation

```bash
composer require preflow/folio
```

Requires PHP 8.4+. Composes `preflow/data`, `preflow/view`, `preflow/routing`, and
`preflow/form` (all already present in a standard Preflow app).

> **Pre-1.0:** Preflow currently publishes `dev-main` only, so your project must allow dev
> stability. The Preflow skeleton already sets `"minimum-stability": "dev"` and
> `"prefer-stable": true`. If you're adding Folio to a different setup, add those to your
> `composer.json` (or require it explicitly with `composer require preflow/folio:@dev`).

## Getting started

Folio is opt-in: a fresh Preflow skeleton does not wire it for you. Three small steps.

### 1. Register the service provider

In `config/providers.php`:

```php
return [
    \App\Providers\AppServiceProvider::class,
    \Preflow\Folio\FolioServiceProvider::class,
];
```

### 2. Choose the admin mount path (optional)

The admin mounts at `/folio` by default. To change it, add `config/folio.php`:

```php
<?php

return [
    'path' => '/admin', // or whatever doesn't collide with your app
];
```

### 3. Define a content type

Content types are JSON files in `config/models/` (the existing `data.models_path`).
Create `config/models/page.json`:

```json
{
    "key": "page",
    "table": "page",
    "storage": "json",
    "id_field": "uuid",
    "label": "Pages",
    "fields": {
        "title":  { "type": "string", "searchable": true, "validate": ["required"] },
        "slug":   { "type": "string", "searchable": true, "validate": ["required"] },
        "body":   { "type": "text" },
        "status": { "type": "string", "validate": ["required", "in:draft,published"] }
    }
}
```

That's it. Start the app and visit `/folio` — a **Pages** area appears with create / edit /
delete. Pages with `status: published` render on the public frontend at their `slug`
(e.g. `/about`), resolved by a lowest-priority catch-all so your own routes always win.

`storage: "json"` writes one flat file per record under `storage/data/page/` — zero
config, no migration. Switch a type to a database by changing `storage` to a configured
driver (e.g. `sqlite`); the admin and rendering are storage-agnostic.

## How it fits together

| Concern | Owner |
|---|---|
| Type definition (`page.json`) | `preflow/data` (`TypeRegistry`, `DynamicRecord`) |
| Record CRUD + validation | `preflow/data` (`DataManager::*Type()`) |
| Auto-form from the type | `preflow/form` |
| Shipped admin (dashboard, CRUD), routing, slug rendering | `preflow/folio` |

Admin and frontend templates live under `@folio/*` and are overridable: drop a same-named
template in `resources/folio/` to replace the shipped one.

## Overriding a core admin action

Shadow a single core action without forking by adding a class under
`App\Folio\Overrides\{Controller}\{Action}` implementing `OverridableAction`:

```php
namespace App\Folio\Overrides\Content;

use Preflow\Folio\Override\OverridableAction;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Index implements OverridableAction
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // your custom dashboard
    }
}
```

## Admin styling

The admin ships a single stylesheet served by Folio itself at
`{prefix}/_assets/admin.css` (no build step, no asset publishing required). The
`<link>` URL is content-hash versioned via the `folio_admin_css_url` Twig
global, so it caches immutably and busts on change.

Dark mode is built in: the stylesheet defines warm-neutral tokens with an
emerald accent and a `[data-theme="dark"]` override. The default follows the
operating system (`prefers-color-scheme`); a sidebar toggle lets the user
override it, persisted in `localStorage` with a no-flash inline script.

Every template is overridable via the `@folio` namespace (drop replacements in
`resources/folio/`). Because the admin renders through action-mode controllers,
the stylesheet link and the small theme scripts are plain markup in
`_layout.twig` rather than going through the asset collector. They already carry
`nonce="{{ csp_nonce() }}"`, so the admin works under Preflow's default strict
CSP. If you override `_layout.twig`, keep the nonces and the
`<meta name="csp-nonce">` tag (the rich-text editor reads it).

## Type options

Besides `fields`, a type JSON can carry two optional blocks.

### `frontend`: public rendering

```json
"frontend": {
    "route": "/blog/{slug}",
    "home": "home",
    "template": "folio/article.twig",
    "published": { "field": "status", "value": "published" }
}
```

- `route`: `{field}` matches one path segment, `{...field}` matches the rest of the
  path. The parameter names are record fields used for the lookup.
- `home` (optional): the value `/` resolves to. Only one type may declare it.
- `template` (optional): a userland Twig path. When it is absent, Folio uses
  `@folio/frontend/{type}.twig`, then `@folio/frontend/page.twig`. Templates receive
  `record`, `rendered` (field → safe HTML), `type` and `preview`.
- `published`: which field and value make a record public. The default is `status` /
  `published`.

Routes are tried most specific first. Two types claiming the same route, or two types
declaring `home`, stop the app at boot with a message naming both. Without any `frontend`
block, a `page` type is served at `/{slug}` exactly as before. The live preview is
available for every type that has a route.

### `list`: the admin list

```json
"list": { "columns": ["title", "status"], "sort": "-title", "orderable": false }
```

- `columns`: the fields shown. The default is the first three
  string/number/select/boolean/date fields.
- `sort`: the default order. A `-` prefix means descending.
- `orderable: true`: up/down buttons that store a manual position in the record's
  `_order` key. New records go to the end.

## Field types

`string`, `text` (`"monospace": true` gives a code-style editor), `number`, `boolean`,
`date` (`"time": true` for date and time), `select` (`"options"` as a map or a list;
`"display": "radio"`), `richtext`, `asset`, `relation` and `matrix`.

A field type's own rules (e.g. `select`'s allowed values) are enforced in addition to
`validate`. To add or replace a type, list the classes in `config/folio.php`:

```php
return ['field_types' => [App\Folio\ColorFieldType::class]];
```

## Status

Shipped:
- type discovery and auto-form CRUD;
- the field types above;
- the matrix field with create-in-drawer and per-placement views;
- side-by-side live preview;
- admin login;
- per-action overrides;
- multi-type frontend routing;
- the configurable list.

The admin runs under Preflow's strict, nonce-based CSP: no inline handlers or styles.
Hand-written `<script>`/`<style>` tags in your own templates need
`nonce="{{ csp_nonce() }}"`.

Planned: content i18n, blocks and singletons, click-to-edit preview, draft/publish, the
asset library, and `folio:pull`. See `docs/superpowers/specs/2026-10-19-*`.
