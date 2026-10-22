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

The admin login uses the session from `config/auth.php`. By default core starts it on
every request, so public pages get a session cookie too. To keep public pages
cookie-free, limit the session to the admin path:

```php
'session' => [
    'cookie' => 'site_admin',
    'paths' => ['/folio'], // prefixes, matched per path segment
],
```

Requests outside those prefixes get no session and therefore no CSRF check either, so
a public form needs its own protection.

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

## Access

Every admin action asks one permission through `can()` (see `preflow/auth`).

**Content types** have `{type}.view`, `create`, `edit`, `publish` and `delete`.
- Viewing a list or a form needs `view`.
- Saving a draft, autosave, discard, restore, sharing and reordering need `edit`.
- Publishing and unpublishing need `publish`. Without it, "Publish" and
  "Publish at" are not offered, and a posted `_action=publish` is refused (403)
  without saving.

**Assets** have `assets.view`, `upload`, `edit` and `delete`.

**The dashboard** needs `folio.dashboard`. Without it, login lands on the first
section the user may view.

**Block editor fragments** need `edit` on any type.

The sidebar, dashboard cards, list buttons and form buttons follow the same
answers. A form the user may not save is shown read-only. Each check is also
enforced on the server, so a hidden button is never the only protection. A
refused htmx request (autosave, block picker) shows its message as a toast.

**Defaults.** Without a permission package, every signed-in staff user may do
everything, as before. For roles from a config file, add `preflow/roles`; for roles
and permissions edited in Folio itself, add [`preflow/rbac`](../rbac/README.md):

```php
// config/roles.php
return ['staff' => [
    'admin'  => ['*'],
    'editor' => ['folio.dashboard', 'page.view', 'page.edit', 'assets.*'],
]];
```

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

Besides `fields`, a type JSON can carry two optional blocks, plus the flags `singleton`
and `publishing` (see below) and its [sidebar](#sidebar) place.

A type's `key` cannot be a path Folio uses under its prefix (`login`, `logout`,
`_assets`, `_uploads`, `_blocks`, `media`, `extensions`, `blocks`, `preview`, `upload`,
`assets`); such a key is an error naming the type.

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
  `record`, `rendered` (field → safe HTML), `type`, `preview`, `locale`, `alternates` and
  `query` (the request's query parameters).
- `published`: which field and value make a record public. The default is `status` /
  `published`. It only applies when [publishing](#drafts-and-publishing) is off for the
  type.
- `permission` (optional): only signed-in users with this permission see the type's
  pages, e.g. `"members.access"`. It must be declared (by an extension's
  `#[DeclaresPermission]` or app code); an undeclared name throws in debug and refuses
  everyone otherwise. Staff with `*` pass too. Allowed pages, redirects and refusal 404s
  are sent with `Cache-Control: private, no-store`. `folio_list()` and `folio_find()`
  (and `Collections`) return nothing for the type to anyone the gate refuses.
- `login` (optional, with `permission`): where refused visitors go, as
  `login?next={the page's URL}`. A path on this site, or one per locale:
  `{"en": "/tools/login", "de": "/de/tools/login"}`. Without it they get a 404.

```json
"frontend": { "route": "/tools/r/{slug}", "permission": "members.access", "login": "/tools/login" }
```

The gate needs the signed-in user, and sessions only exist on `session.paths` (when
config/auth.php sets them): include the route's prefix there (`/tools`, and `/de/tools`
for prefixed locales), or every visitor counts as signed out. A share-preview link does not
open the gate: it stays a staff tool, and the reviewer must pass the gate as well. The
admin live preview is unaffected. The gate fails closed: without auth configured nobody is
signed in, so nobody passes it.

Any template can read other content too:

- `folio_get('site')`: a singleton's values.
- `folio_list('app')`: a collection's records, in the type's list order (`orderable`
  position or `sort`).
- `folio_find('force', id)`: one record, or null.

All three return live content only: published snapshots of publishing types, so drafts
never reach the site. In the admin preview they return working copies. Values are
localized to the page's locale. App code gets the same lists from
`Preflow\Folio\Content\Collections`.

Rich text arrives sanitized wherever a template reads it: in `rendered`, in blocks
(nested data-only blocks too), and from `folio_get`, `folio_list` and `folio_find`. That
holds however the value was stored (admin save, import, app code), so `|raw` is safe for
`richtext` fields. Other fields stay plain text and are escaped as usual.

Routes are tried most specific first. Two types claiming the same route, or two types
declaring `home`, stop the app at boot with a message naming both. Without any `frontend`
block, a `page` type is served at `/{slug}` exactly as before. The live preview is
available for every type that has a route.

### `list`: the admin list

```json
"list": { "columns": ["title", "status"], "sort": "-title", "orderable": false }
```

- `columns`: the fields shown. The default is the first three
  string/number/select/boolean/date fields that are not hidden.
- `sort`: the default order. A `-` prefix means descending.
- `orderable: true`: up/down buttons that store a manual position in the record's
  `_order` key. New records go to the end.

### Sidebar

```json
{ "key": "site", "singleton": true, "section": "settings", "order": 10, "icon": "ui-settings" }
```

- `section`: the sidebar section, a lowercase name (`a-z`, `0-9`, `_`, `-`, starting
  with a letter). The default is `content` (extension settings types: `settings`); a
  `section` in an extension model wins over that.
- `order`: an integer; lower comes first within the section. The default is 100.
- `icon`: a `ui-*` icon of Folio's sprite (for example `ui-file`, `ui-settings`,
  `ui-users`, `ui-key`). The default is `ui-settings` for singletons, `ui-file`
  otherwise.
- `nav: false` leaves the type out of the sidebar; its admin pages stay reachable.

The sections, in this order: the dashboard, **Pages** (`pages`), **Content**
(`content`), **Library** (`library`), **Settings** (`settings`). Any other name sits
between Content and Library (several, in the order first used), labelled from the name (`member_area` becomes "Member area").
Sections the user can see nothing in are left out. A wrong type for any of these keys is
an error naming the type.

## Field types

`string`, `text` (`"monospace": true` gives a code-style editor), `number`, `boolean`,
`date` (`"time": true` for date and time), `select` (`"options"` as a map or a list;
`"display": "radio"`), `richtext`, `asset`, `relation` and `matrix`.

Any field also takes:

- `"hidden": true`: not shown in the edit form or the default list columns. A save keeps
  the stored value; a posted value is ignored.
- `"readonly": true`: shown in the edit form as plain text, without an input. A save keeps
  the stored value; a posted value is ignored.

Both apply on create too: such a field starts empty unless a `#[BeforeSave]` hook sets it.

A field type's own rules (e.g. `select`'s allowed values) are enforced in addition to
`validate`. To add or replace a type, list the classes in `config/folio.php`:

```php
return ['field_types' => [App\Folio\ColorFieldType::class]];
```

A field type's `assets()` names the `.css` and `.js` files its editor needs; Folio loads
them only on forms that render the field, from `{prefix}/_assets/{file}`. A package
serves its own files there by adding them in its provider's `boot()`:
`$container->get(Preflow\Folio\Http\AdminAssets::class)->add('color.css', __DIR__ . '/../assets/color.css')`
(an absolute path; a name already taken is an error).

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
- **Data-only blocks** are plain classes with the attributes, in `app/Blocks/` under the
  `App\Blocks\…` namespace (or listed in `config/folio.php`).
  They have no template; the parent's template iterates them (`{% for link in links %}`).
- **Discovery:** blocks are found in `app/Components`, `app/Blocks`, and in
  `config/folio.php` `'blocks' => ['classes' => [...], 'max_depth' => 4]`. Duplicate keys
  fail at boot. When debug is off the registry is cached in `storage/cache`; the cache
  is keyed by the block classes and their files' modification times, so it rebuilds by
  itself when a block is added, removed or edited.

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
- Collapsible cards with move up/down, duplicate and remove. Duplicate is server-rendered:
  the copy gets fresh ids throughout and keeps other languages and uploaded files.
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
- Templates must pass `preview` (`folio_blocks(record.sections, preview)`) for the
  click-to-edit markers to appear in the live preview.
- Block templates receive raw field values. Escape them as usual (`{{ headline }}`).
  For a `richtext` block field, the editor sanitizes on save, so `{{ body|raw }}` is the
  intended output.
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

## Drafts and publishing

Types with a `frontend` block and singletons publish by default. Set
`"publishing": true|false` in the type JSON to override that.

**How it works:**
- The admin edits a **working copy**.
- The public site reads a **published snapshot**, stored in `{table}__published` with
  the same id.
- `folio_get()`, the frontend routes and per-language visibility (`_locales`) all come
  from the snapshot. The live preview shows the working copy.

**States** are derived by comparing the two copies, and shown in the list and editor
header:
- **Draft:** nothing published yet.
- **Published:** the snapshot matches the working copy.
- **Changed:** there are unpublished edits.

**Editing:**
- **Save draft** stores half-finished work and runs no validation rules.
- **Publish** validates fully, then copies the working copy to the snapshot. When
  validation fails, the edits stay saved as a draft and the live page is untouched.
- **Discard changes** resets the working copy to what is published.
- **Unpublish** removes the snapshot, so the public URL returns 404.
- **Autosave:** existing records save themselves 3 seconds after the last change.
  Uploads are only stored on a manual save.
- **Conflicts:** each save sends the `_version` it was loaded with. If someone else
  saved in between, the save is refused ("Someone else changed this record"). Saving
  again overwrites their change; reloading discards yours.
- **Revisions:** every publish keeps a full copy in `{table}__revisions`, with the date,
  the user and the fields that changed. **Restore** copies one into the working copy,
  and it then has to be published again. Keep more or fewer revisions with
  `config/folio.php`:

  ```php
  'publishing' => ['revisions' => 20, 'preview_ttl' => 604800],
  ```

**Scheduled publishing:** "Publish at" stores a date and time on the working copy.
Publish what is due from cron:

```
*/5 * * * * cd /path/to/app && php preflow folio:publish-scheduled
```

The command holds a lock, so overlapping runs do nothing. A record that fails validation
stays scheduled and is reported on every run. To make Folio's commands available, list
them in `config/commands.php`:

```php
return [...\Preflow\Folio\Console\FolioCommands::all()];
```

`php preflow folio:publish <type> [--id=<id>] [--all]` publishes the drafts of a type
at once. By default it skips records that are already published, and records whose
legacy `frontend.published` field (e.g. `status`) does not say published. `--all`
publishes every working copy, including unfinished edits.

"Publish at" is a wall-clock time in `app.timezone` (`config/app.php`), which Preflow
applies on boot, in web requests and in the CLI alike. A time in the past is not
stored.

**Upgrading an existing site:** publishing switches on by itself for types with a
`frontend` block and for singletons. Until their records are published:
- their pages return 404;
- `folio_get()` returns `null`.

After deploying:
1. On SQL storage, run the migrations below.
2. Run `php preflow folio:publish <type>` for each such type.
3. Or set `"publishing": false` to keep the previous behaviour.

**Share preview links:**
- **Copy preview link** creates a signed URL to the record's real page that shows the
  working copy to anyone holding it, without logging in.
- Links expire after `preview_ttl` seconds (7 days by default).
- **Revoke all links** invalidates every earlier link for that record.
- Responses carry `X-Robots-Tag: noindex` and `Cache-Control: private, no-store`.
- Links are signed with `APP_KEY` and are disabled while it is empty or still the
  default.

**Referenced records:** relations, matrix placements and `folio_url()` follow the same
rule on public pages: they show the published copy of a publishing type, and nothing
for a record that is only a draft. The admin, the live preview and share-preview links
show working copies.

**Live content in your own code:** read published content through
`Preflow\Folio\Publishing\Publisher`. `->query('article')` returns a `QueryBuilder` over the
snapshots (or over the records themselves when the type does not publish), and
`->live($type, $id)` returns one record. Querying the type directly with `DataManager`
returns working copies, drafts included.

**SQL storage:**
- Create `{table}__published` with the type's columns plus `_published_at` and
  `_published_by` (TEXT).
- Create `{table}__revisions` with `uuid` (primary key), `record_id`, `seq` (INTEGER),
  `data`, `changed`, `created_at` and `created_by`.
- Add `_version` (INTEGER), `_publish_at` and `_share_token` (TEXT) to the type's own
  table. A cleared schedule is stored as `''`, because the SQL drivers skip null values.
- JSON-file storage needs no setup.

## Asset library

Uploads go into a shared media library at `{prefix}/assets`. Files are written to
`public/media/Y/m/{id}.{ext}` and served by the web server directly, with no PHP
involved. Their metadata (alt text per language, title, focal point, video poster)
are records of an internal type, stored in the `folio_assets` table.

**Library screen:**
- grid and list views;
- search by name, title or alt text, and filters by kind (images, videos, documents);
- multi-file upload;
- a details panel for alt text, title and focal point (click the image to set it), and
  a poster image for videos;
- **usage:** the records that use the asset, including published versions that still
  show it. Deleting a used asset is refused.

**Asset fields** store asset ids. The editor shows the selection with **Choose**, which
opens the library as a picker in the drawer, and **Upload**, a plain file input that adds
the file to the library on save, so it works without JavaScript. A field's `accept`
(`image/*`, `video/*` or `.pdf,.zip`) limits what an upload may be.

**Files in templates:** `{{ folio_asset_url(record.whitepaper) }}` gives an asset's public
URL (`''` without one), for download links and custom markup. It accepts what an asset
field stores: a library id, a legacy upload path, or a list (its first item).

**Images in templates:**

```twig
{{ folio_image(record.cover, 'hero', {sizes: '(min-width: 60rem) 50vw, 100vw', class: 'cover'}) }}
```

- It emits `<picture>` with AVIF and WebP `srcset`s, and an `<img>` with `width`,
  `height`, `loading` and the alt text in the page's language.
- Options and presets are those of `responsive_image()`: `config/image.php`
  `presets` (`widths`, `sizes`, `loading`, `class`, `attrs`), plus two of Folio's own:
  - `step: 160` adds widths every 160 px between the preset's smallest and largest
    width, so browsers can pick close to the size they display. Those widths are made
    on first request (see below).
  - `priority: true` is for the page's key image (a hero or portrait). It sets
    `fetchpriority="high"` and `loading="eager"` and adds one
    `<link rel="preload" as="image" imagesrcset imagesizes>` to the head per image. A
    call can override it: `folio_image(img, 'hero', {priority: false})`. The link is
    added while the image renders. A layout that prints `{{ head() }}` before the body
    has already written its head by then, so the link is missing there. Leave the head
    to the app's automatic injection (the default) to keep it.
- The focal point becomes classes (`folio-focal folio-fx-30 folio-fy-70`), never an
  inline style. Their CSS is added to the page with the CSP nonce. It only shows when
  your CSS crops the image, e.g. `object-fit: cover`.
- Asset fields in a type's default template render the same way.

**Variants** are made on upload with GD or Imagick: every configured width the image
can fill, plus its own width when it is narrower than the largest configured width. Each
is made as WebP, and as AVIF where supported. Phone photos are turned upright using their
EXIF orientation. Images above `max_pixels` (30 megapixels by default) keep only the
original: decoding them could exceed a shared host's memory limit. The
widths come from `assets.widths`, else from all preset widths in `config/image.php`. GIF
and SVG files are served as they are, and without GD or Imagick no variants are made.
After changing widths, run `php preflow folio:assets:variants`.

**Step widths on first request.** The listed `widths` are made on upload. Step widths
are made the first time a browser asks for one:
- the missing file reaches the front controller, and Folio makes it, writes it next to
  the original and returns it;
- from then on the web server serves it as a static file;
- only widths a preset defines, never wider than the original and in formats the backend
  supports, are made. Anything else under the media URL is a 404 that writes nothing;
- concurrent first requests wait on a per-file lock, so a variant is encoded once.

`php preflow folio:assets:variants --all` makes the step widths in advance as well, and
`warm_on_upload` makes them during each upload. Use one of them on shared hosting, so a
page full of new images doesn't start many encodes at once on its first view. If an
encode fails, the browser is redirected (uncached) to the closest existing size, never
shown a broken image. The media URL belongs to the library: a page under `/media/…`
is not routed.

**Config** (`config/folio.php`):

```php
'assets' => [
    'dir' => __DIR__ . '/../public/media', // default
    'url' => '/media',
    'storage' => 'json',                  // a data driver name
    'max_bytes' => 20 * 1024 * 1024,
    'max_pixels' => 30_000_000,          // larger images get no variants
    'warm_on_upload' => false,           // true: make the step widths during the upload too
    'allow_svg' => false,
    'widths' => [480, 768, 1024, 1600],   // default: the config/image.php preset widths
    'quality' => 75,
    'private_dir' => __DIR__ . '/../storage/private-media', // default; never under public/
    'private_url' => '/files',                // default
    'private_permission' => 'folio.dashboard', // default
    'private_login' => null,                  // optional: '/tools/login'
],
```

**Private assets.** An asset field with `"private": true` stores its uploads in
`private_dir`, outside `public/`:

```json
"pdf": { "type": "asset", "asset": { "accept": "application/pdf", "private": true } }
```

- Private files get no variants, and nothing under the media URL serves them.
- A private field only takes private assets, and a public field only public ones. Its
  picker lists just those, and its uploads match. Choosing the other kind is refused with
  a field error, so a public file never ends up in a private field. Without the asset
  library, a private field refuses uploads.
- Their only URL is `{private_url}/{id}`, e.g. `/files/3f…e1`. `folio_asset_url()`,
  `folio_image()` (a plain `<img>`, no srcset) and the admin thumbnails all use it.
- The route serves the file only to a signed-in user with `private_permission`; staff
  with `*` pass. It fails closed like the frontend gate.
- Anyone else gets a 403, or with `private_login` a redirect to
  `login?next={the file's URL}`.
- Files are sent with `Cache-Control: private, no-store` and their stored type. Images
  are `inline`; everything else, SVG included, is an `attachment` with the original
  file name.
- The route needs the session: when config/auth.php sets `session.paths`, include the
  private URL's prefix (`/files`, or `/tools` for `/tools/files`).
- A `private_dir` inside `public/` or the media directory is refused at boot.
- Export and pull carry the private files with their asset records (archive root
  `private-media/`). An archive from before private assets has no such root, and
  importing it leaves local private files alone.

For a members area:

```php
'assets' => ['private_url' => '/tools/files', 'private_permission' => 'members.access', 'private_login' => '/tools/login'],
```

**Allowed files:**
- The type is sniffed from the file's content, never taken from the client's file name,
  and the stored extension follows the sniffed type. So a `shell.php` renamed to `.jpg`
  cannot land in the public directory.
- Allowed: JPEG, PNG, GIF, WebP, AVIF, MP4, WebM, QuickTime (`.mov`), PDF, plain
  text, CSV, ZIP, and Word, Excel and PowerPoint files (`.doc`, `.docx`, `.xlsx`, `.pptx`).
- A refused upload in a field (wrong type, too large, not matching the field's `accept`)
  shows as a field error, and the record is not saved.
- **SVG is refused** unless `allow_svg` is set: an SVG served from your own domain can
  run scripts.

A content type keyed `assets` keeps working on the site, but its admin list is
hidden behind the library at `{prefix}/assets`. Rename the type to edit it.

**Older uploads:** paths stored before the library existed keep rendering through
`{prefix}/_uploads`. `php preflow folio:assets:import [--dry-run]` moves them into the
library:
- it rewrites every reference to them, in working copies, published versions,
  revisions and nested blocks;
- it lists files that are missing;
- running it again changes nothing.

**SQL storage:** create `folio_assets` with:
- `uuid` (primary key);
- `path`, `original_name`, `mime`, `title`, `poster`, `created_at` and `created_by`
  (TEXT);
- `size`, `width`, `height`, `focal_x`, `focal_y` and `private` (INTEGER);
- `duration` (REAL);
- `alt` and `variants` (TEXT, JSON).

**Dev server:** the PHP built-in server must serve `public/` as its document root for
`/media` files:

```
php -S 127.0.0.1:8000 -t public public/index.php
```

`public/index.php` should `return false` for existing files under `cli-server`.

## Extensions

An extension adds screens, type customizations, models and settings to Folio without
touching Folio itself. A working one ships in
[`examples/folio-demo/app/Folio/Demo`](../../examples/folio-demo/app/Folio/Demo); the examples below are
copied from it.

**Where they live.** A project extension is a folder `app/Folio/{Dir}/` with an
`Extension.php` (class `App\Folio\{Dir}\Extension`). A package names its extension class
in `composer.json`: `"extra": {"folio": {"extension": "Vendor\\Pkg\\Extension"}}`.

```php
// app/Folio/Demo/Extension.php
#[FolioExtension(name: 'demo', label: 'Demo', description: 'A screen, a note customization and a setting', icon: 'ui-puzzle', version: '1.0.0')]
#[DeclaresPermission('demo.view', 'See the demo screen')]
#[DeclaresPermission('demo.shout', 'Shout a note')]
final class Extension extends \Preflow\Folio\Extension\Extension
{
    public function settings(): array
    {
        return [
            'greeting' => ['type' => 'string', 'label' => 'Greeting', 'default' => 'Hello from the demo extension'],
        ];
    }
}
```

The folder's other parts are found by their attributes: `Screens/*` (`#[FolioScreen]`),
`Types/*` (`#[FolioType]`), `models/*.json` (content types in the model JSON format) and
`templates/` (Twig, namespace `@{name}`, e.g. `@demo/overview.twig`). A part is named
`screen:{path}`, `type:{key}`, `model:{key}` or `settings`.

**Enabling.** Nothing runs until `config/folio/extensions.json`, which is committed,
lists it:

```json
{"enabled": {"demo": {"disabled_parts": []}}}
```

A site without that file and without `app/Folio/*/Extension.php` behaves exactly as
before. Misnamed classes, missing templates, duplicate routes and unknown names are
errors on boot in debug (in production they are logged and the part is skipped);
`folio:extensions:check` always treats them as errors.

**Permissions.** An extension declares each permission it uses with `#[DeclaresPermission]`;
the name must start with `{extension}.`, and the extension's label is its group in
`preflow/roles`. An undeclared permission throws in debug. While the extension or the part
is disabled its permissions are denied (and logged once), never a 500.

**Management.** *Settings → Extensions* (`{prefix}/extensions`, permission
`folio.extensions`) lists extensions, parts and installed versions. It is read-only unless
`config/folio.php` has `'extensions' => ['allow_admin_changes' => true]` (default:
`app.debug`); then it toggles extensions and parts by writing `extensions.json`.

```
php preflow folio:extensions:list
php preflow folio:extensions:enable  <name> [--part=<id>]
php preflow folio:extensions:disable <name> [--part=<id>]
php preflow folio:extensions:apply
php preflow folio:extensions:check
```

- `disable <name>` works without booting the app, so it is the recovery path for an
  extension that breaks boot or whose folder is gone. Disabling an extension forgets
  its `disabled_parts`.
- `apply` runs the Folio table sync (as `preflow migrate` does) and then `install()` of
  every enabled extension, once per version per environment (the installed version is
  kept in the environment's own storage, never exported). Run it on every deploy.
- `check` boots with debug forced on and exits 1 on any extension error; use it in CI.

### Screens

A screen is an admin page under the Folio prefix, in the sidebar, behind a permission.
Its methods carry `#[Get]` / `#[Post]` with paths relative to the screen. The class is
built from the container, so it can take constructor dependencies.

```php
// app/Folio/Demo/Screens/Overview.php
#[FolioScreen('demo', 'Demo overview', icon: 'ui-file', permission: 'demo.view')]
final class Overview
{
    public function __construct(private readonly DataManager $data) {}

    #[Get('/')]
    public function index(): ScreenView
    {
        return new ScreenView('@demo/overview.twig', [
            'notes' => $this->data->queryType('note')->get()->items(),
        ]);
    }
}
```

```twig
{# app/Folio/Demo/templates/overview.twig #}
{% extends '@folio/admin/_layout.twig' %}
{% block title %}{{ screen.label }} — Folio{% endblock %}
{% block page_title %}{{ screen.label }}{% endblock %}
{% block content %}
    <p class="demo-greeting">{{ folio_setting('demo', 'greeting') }}</p>
    <p>{{ notes|length }} notes</p>
{% endblock %}
```

- `FolioScreen(path, label, icon, section, order, permission)`: the permission is
  required. `section` is `settings` by default; a new name adds a sidebar section after
  the built-in ones.
- A method returns a `ScreenView(template, data)` (rendered in the admin layout with
  `prefix`, `csrf` and `screen` added to your data), a string, or any PSR-7 response.
  POSTs need the CSRF token (`_csrf_token`, available as `csrf` in the template).
- A path colliding with a type key or a Folio path (`login`, `logout`, `_assets`,
  `_uploads`, `media`, `extensions`) is a boot error.
- The sidebar is data: `folio_nav()` in Twig, `Preflow\Folio\Http\AdminNav` in PHP
  (`add()` for your own items). Session flashes for the layout come from `folio_flashes()`.

### Type customizations

A class with `#[FolioType('note')]` customizes the admin of an existing type (an unknown
key is a boot error). It is built from the container; its methods carry:

```php
// app/Folio/Demo/Types/NoteTweaks.php
#[FolioType('note')]
final class NoteTweaks
{
    public function __construct(private readonly DataManager $data) {}

    #[FormPanel('Name length')]
    public function nameLength(DynamicRecord $record): string
    {
        return mb_strlen((string) $record->get('name')) . ' characters';
    }

    #[RecordAction('shout', 'Shout', 'demo.shout', icon: 'ui-check', confirm: 'Upper-case this note\'s name?')]
    public function shout(DynamicRecord $record): null
    {
        $record->set('name', mb_strtoupper((string) $record->get('name')));
        $this->data->saveType($record, false);

        return null;
    }
}
```

| Attribute | Method signature | Effect |
|---|---|---|
| `#[FormPanel(label, position: 'after')]` | `fn(DynamicRecord): ScreenView\|string` | A panel on the edit form (not on create), `before` or `after` Folio's own extras. A string is shown as escaped text, a `ScreenView` as a template fragment. |
| `#[RecordAction(name, label, permission, icon, confirm)]` | `fn(DynamicRecord, ServerRequestInterface): ?ResponseInterface` | A POST button at `{prefix}/{type}/{id}/_action/{name}`; null redirects back to the form with "{label}: done". Needs the permission and the type's edit permission. |
| `#[ListColumn(label, position: 100)]` | `fn(DynamicRecord): string` | A column after the type's own, ordered by position, shown as escaped text. |
| `#[NavBadge]` | `fn(): ?int` | A count badge on the type's sidebar item (e.g. waiting members), computed only while the item is visible and at most once per request; `null`, `0` or any non-int return shows none, a callback that throws shows no badge (the admin still renders), several add up, one per class. Keep it cheap: it runs on admin page renders. |
| `#[BeforeSave]` | `fn(DynamicRecord): void` | Before validation; may `set()` fields. Throw `Preflow\Validation\ValidationException` to refuse the save: the form is shown again with its errors (422). |
| `#[AfterSave]` | `fn(DynamicRecord): void` | After the record is stored. |
| `#[BeforeDelete]` | `fn(DynamicRecord): void` | Throw `Customization\Refused('message')` to keep the record; the message is shown to the editor. |
| `#[AfterDelete]` | `fn(DynamicRecord): void` | After the record is gone. |

Things to know:

- **Hooks** run on the admin store, update (plain and publishing types) and destroy. They
  do **not** run on reorder, nor on the publishing controller's restore, discard or autosave.
- **Record actions:** a click submits only the action's own form, so unsaved edits in the
  edit form are not saved. On a publishing type the action receives the working copy;
  saving it directly (as above) bypasses the Publisher's versioning, so changes appear in
  the next publish rather than as a revision.
- Duplicate action names on a type are a boot error. A disabled `type:{key}` part has no
  effect.

### Settings

`Extension::settings()` returns a form in the model JSON `fields` format. Folio shows it
under *Settings* as the singleton type `{name}_settings` (no publishing), labelled
"{extension label} settings" (e.g. "Members settings"), and reads it with
the declared defaults. Overriding `settings()` declares the `settings` part; discovery sees
that without instantiating the extension, so a disabled extension's constructor never runs.
An enabled one whose `settings()` returns no fields is an error on boot.

```php
'greeting' => ['type' => 'string', 'label' => 'Greeting', 'default' => 'Hello from the demo extension'],
```

```twig
{{ folio_setting('demo', 'greeting') }}
```

```php
$settings = $container->get(\Preflow\Folio\Extension\Settings\ExtensionSettings::class);
$settings->get('demo', 'greeting');          // the stored value, else the default, else null
$settings->get('demo', 'greeting', 'de');    // translatable fields: a locale
```

- Before the first save the form and `get()` return the declared defaults.
- Permissions are the settings type's standard ones: `{name}_settings.view` and
  `{name}_settings.edit`.
- Only `null` (never saved) counts as "not set": a cleared string reads `''`, and an
  unchecked boolean reads `false` on every storage, also where SQL hands it back as `''`.
- An unknown extension or key throws in debug and returns null in production.
- **Secrets stay out of settings.** A field of `'type' => 'env', 'env' => 'MAIL_PASSWORD'`
  stores nothing and shows only "set" or "not set"; `get()` reads the environment variable.

## User types and transfer

A type can hold the site's own users or members:

```json
{ "key": "member", "users": "members", "storage": "sqlite", "fields": { "email": { "type": "string" } } }
```

- `"users": "<population>"` marks a type as user data. It is **not** transferred
  (`folio:export`, `folio:import`, `folio:pull`) unless it also says `"transfer": true`:
  live users never travel to a laptop by accident.
- Any other type can opt out with `"transfer": false`. `"transfer"` must be a JSON boolean;
  anything else (`"false"`, `0`) is an error naming the type.
- A type that is not transferred cannot have fields that hold files (`asset`, `blocks`,
  `matrix`) yet: its records stay on the server, but the uploaded files would still be
  exported. Such a type is an error. Field types a site registers itself
  (`config/folio.php` `field_types`) are not checked; don't give one that stores files
  to a type that is not transferred.
- Per-environment state (installed extension versions, throttle rows) is never exported.

**Signing users in.** `Preflow\Folio\Users\RecordUserProvider` is a user provider over a
user type, for a `SessionGuard` in `config/auth.php`:

```php
'providers' => [
    'members' => [
        'class' => Preflow\Folio\Users\RecordUserProvider::class,
        'type' => 'member',
        'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles',  // the defaults
    ],
],
'guards' => [
    'members' => ['class' => Preflow\Auth\SessionGuard::class, 'provider' => 'members'],
],
```

- The type must exist and have `"users"` set, or boot fails with a message naming it.
- `findByCredentials()` lowercases and trims the typed email and accepts only an exact match
  of the stored value, and only when exactly one record has it: a database that compares
  loosely (MySQL collations) never lets a look-alike or duplicate pick an account. Store
  emails lowercase.
- The record is the signed-in `RecordUser`; its `roles` field (a list or a JSON/comma
  string) feeds the permission resolver.

## SQL storage

Folio content can live in SQLite by setting `"storage": "sqlite"` in a type's model JSON
(a storage named in a model that is not configured in `config/data.php` is an error, not a
fallback to the default). Tables come from the model JSON, with no migration files:

```
php preflow migrate
# [folio.tables] created table post
# [folio.tables] created table post__published
```

- The step creates the type's table, the `__published` and `__revisions` shadows of
  publishing types, the asset table, and Folio's own internal tables. The id is
  `TEXT PRIMARY KEY`, every other column nullable `TEXT`.
- It **creates and adds only**: missing tables and columns are added, nothing is ever
  dropped, renamed or altered. Re-running it is safe.
- **SQLite only for now.** A Folio type stored on MySQL makes the step throw.
- A record key without a column (a JSON field added to the model before migrating) fails
  with a message naming `preflow migrate`, not a raw PDO error.
- JSON-stored types are left alone. `folio:extensions:apply` runs the same sync.
- Lists ordered by `_order` compare numerically (`orderBy(..., numeric: true)`), also
  where the column is text.

## Live content to local: `folio:pull`

Content is edited live; code goes through git. To work on a copy of the live content:

```php
// config/folio.php
'remotes' => [
    'production' => ['ssh' => 'user@host', 'path' => 'www/site/current', 'port' => 22, 'php' => 'php'],
],
```

```
php preflow folio:pull production --dry-run   # what would change
php preflow folio:pull production             # backup, then replace local content
php preflow folio:pull production --only=data # records only (or --only=media)
```

- **What content means:** the records of every Folio type (with their published and
  revision copies), the asset records, the media directory and legacy uploads.
  Never code, `.env`, caches, logs, sessions or other tables (`user_tokens`).
- **SSH:** your SSH agent and `~/.ssh/config`, with no credentials in config. Host keys
  are never ignored, so a changed key aborts.
- **Remote settings:**
  - `ssh` is a host alias or `user@host`, never an ssh option.
  - `path` is absolute or relative to the remote home, without `~`.
  - `php` is a single binary path (`/usr/local/bin/php84`), without arguments.
- **Symlinks:** a symlinked media root (a Deployer `shared/` link) is followed. Symlinked
  subdirectories inside it are skipped.
- **Refused on import:** dotfiles (`.htaccess`, `.user.ini`) and executable files (`.php`,
  `.phtml`, `.sh`, …) under media or uploads.
- **On the server:** it runs `php preflow folio:export --stdout`, so the remote needs
  this Folio version and PHP CLI.
- **Locally:** before anything changes, a backup of the local content goes to
  `storage/backups/pull-YYYYmmdd-HHMMSS.tar.gz`. A failed or broken remote export changes
  nothing.
- **Local-only content:** records and files that exist only locally are removed, so the
  copy matches live.

The same archive works without SSH, as a content backup and restore:

```
php preflow folio:export backup.tar.gz        # or --stdout
php preflow folio:import backup.tar.gz [--dry-run]
```

There is deliberately no `folio:push`: it would silently overwrite live edits.

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
- blocks and singletons;
- drafts and publishing (autosave, conflicts, revisions, scheduled publishing, share
  links);
- the asset library (variants, `folio_image()`, picker, usage, legacy import);
- click-to-edit in the live preview;
- `folio:pull` (live content to local);
- permissions on every admin action (`can()`, with `preflow/roles` or `preflow/rbac` for roles).

The admin runs under Preflow's strict, nonce-based CSP: no inline handlers or styles.
Hand-written `<script>`/`<style>` tags in your own templates need
`nonce="{{ csp_nonce() }}"`.
