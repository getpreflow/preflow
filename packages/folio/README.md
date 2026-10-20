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

## Blocks

A block is a Preflow component whose fields are PHP attributes. Schema, logic, template
and CSS live in one component folder:

```php
#[Block(key: 'hero', label: 'Hero', group: 'Sections', icon: 'block')]
final class Hero extends BlockComponent
{
    #[BlockField(type: 'string', translatable: true, validate: ['required'])]
    public string $headline = '';

    #[BlockField(type: 'blocks', allowed: ['link'], max: 3)]
    public array $links = [];
}
```

- **`key`** is the stable storage id; renaming the class never orphans content.
- **Field types:** `#[BlockField]` takes every field type and option that JSON type
  fields take (`label`, `help`, `validate`, `translatable`, `config` for things like
  `options`). `blocks` nests further.
- **Component blocks** extend `BlockComponent`. Their `#[BlockField]` properties are
  filled from the block's data (already in the page's language) before
  `resolveState()`.
- **Data-only blocks** are plain classes with the attributes (put them in `app/Blocks/`).
  They have no template; the parent's template iterates them (`{% for link in links %}`).
- **Discovery:** blocks are found in `app/Components`, `app/Blocks`, and in
  `config/folio.php` `'blocks' => ['classes' => [...], 'max_depth' => 4]`. Duplicate keys
  fail at boot, and the registry is cached in `storage/cache` when debug is off.

**On a content type:** `"sections": {"type": "blocks", "allowed": ["hero", "text"], "min": 1, "max": 10}`.

**Storage:** an ordered list inside the record, so nothing extra to migrate.

```json
[{"_uid": "a1b2c3d4e5f60718", "_block": "hero", "headline": {"en": "…", "de": "…"}, "links": [ … ]}]
```

- **Language:** structure (which blocks, their order) is shared across languages;
  translatable leaves hold one value per language.
- **Removed classes:** a block whose class was removed is kept untouched, shown in the
  editor as "Unknown block" (removable), and skipped on the website.

**Editing:**
- Collapsible cards with move up/down, duplicate (fresh ids) and remove.
- Grouped "Add block" buttons that load a server-rendered card (htmx, served from the
  admin itself).
- Inputs are named by block id (`sections[<uid>][headline]`), and each card carries its
  own order input, so reordering never renumbers anything.
- Errors show on the card, with a summary that links to it.
- Uploads work inside blocks.

**Rendering:**
- `{{ folio_blocks(record.sections, preview) }}` renders each block through its
  component, in order and in the page's language.
- `{{ folio_block(block) }}` renders one.
- A block that throws is contained by the component error boundary.
- In live preview each block is wrapped in `data-folio-block="<uid>"`.

## Blocks, matrix or relation?

- **blocks:** content this record owns, edited in place (page sections).
- **matrix:** an ordered mix of references to standalone, reusable records.
- **relation:** references to records of one type.

## Singletons

`"singleton": true` makes a type hold exactly one record, such as site settings:

- **Admin:** its nav entry opens the edit form straight away. There is no list, "new"
  or delete, and the first save creates the record (id = the type key).
- **Templates:** `{% set site = folio_get('site') %}` returns its values in the page's
  language, or `null` before the first save, loaded once per request. In PHP, inject
  `Preflow\Folio\Content\Singletons`.

## Translations

With more than one locale in `config/i18n.php`, fields marked `"translatable": true`
hold one value per language: `{"en": "About", "de": "Über uns"}`. Everything else is
shared across languages. With a single locale, the flag has no effect and values stay
plain.

```php
// config/i18n.php
return ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];
```

**Editing:**
- The edit form has language tabs (`?locale=de`). Saving one language never touches the
  others.
- Fields shared across languages are labelled.
- An empty translation shows the default-language text as a placeholder ("Falls back to
  English"), and a collapsible reference shows the default-language text.
- **Visible in** checkboxes set the record's `_locales`. The default language is always
  on.
- The list shows a badge per language, e.g. `de · 2 missing` when fields required in
  the default language are empty there.

**Website:**
- `/` and `/about` serve the default language; `/de/…` serves German, with translated
  route values (e.g. `/de/ueber-uns`).
- A page not visible in a language returns 404 there.
- Missing translated values fall back field by field: requested, then fallback, then
  default.
- Templates receive `locale` and `alternates` (`[{locale, url}]`).
- `{{ folio_alternate_links(alternates) }}` emits the `hreflang` links (including
  `x-default`).
- `{{ folio_url('page', id, 'de') }}` links to a record in a language.
- Referenced records (relation, matrix) render in the page's language.

**Validation:** `required` applies to the default language only. Other rules apply to
every language that has a value; errors are keyed `field.de`.

Older values stored before a field became translatable keep working as the default
language's value. Upload fields are always shared.

**SQL storage:** translatable values and the record's `_locales` list are stored as JSON
text. A SQL-backed type needs a `_locales` (TEXT) column, and its translatable columns
must be wide enough for the JSON map. JSON-file storage needs no setup.
`folio_alternate_links()` emits absolute URLs when the request has a host, which
search engines require.

## Status

Shipped:
- type discovery and auto-form CRUD;
- the field types above;
- the matrix field with create-in-drawer and per-placement views;
- side-by-side live preview;
- admin login;
- per-action overrides;
- multi-type frontend routing (the home record is served at `/`);
- the configurable list;
- content translations;
- blocks and singletons.

The admin runs under Preflow's strict, nonce-based CSP: no inline handlers or styles.
Hand-written `<script>`/`<style>` tags in your own templates need
`nonce="{{ csp_nonce() }}"`.

Planned: click-to-edit preview, draft/publish, the
asset library, and `folio:pull`. See `docs/superpowers/specs/2026-10-19-*`.
