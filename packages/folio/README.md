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

**Cache files:** Folio keeps `folio-extensions.cache`, `folio-blocks.cache` and
`folio-translations-{locale}.php` in `storage/cache`. `config/folio.php` `'cache_path'` moves
them: an absolute path is used as it is, a relative one is taken from the project root.
Folio creates the directory when it writes. Core's own caches (`storage/cache/data`, compiled
templates) are not affected. A test suite can point `cache_path` at a temp directory, so
booting the app never writes into the project's cache:

```php
return [
    'cache_path' => sys_get_temp_dir() . '/my-site-folio-cache',
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

**The Translations screen** needs `translations.edit` (an `admin` role with `*` has it).
Choosing one's own admin language needs no permission.

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

**The own account page** (`{prefix}/_account`) asks no permission
(`FolioPermissionMiddleware::SIGNED_IN`): every signed-in record user may open it, an account
without roles too. Any other admin handler without a rule is refused (fail closed).

### Staff login

The login page has the same options for every site:

- **Remember me.** The checkbox appears when the default guard has `remember` configured
  (see preflow/auth). A config-only site gets it too once it configures one.

The rest applies only when the default guard has **record users**: a `RecordUserProvider`,
directly or in a `ChainUserProvider`. A site whose staff are config users only keeps its
login exactly as before: the same page, no throttle, no forgot link, and no new routes.

- **Throttle.** Sign-in is throttled (`folio.login`, 10 attempts per 15 minutes, counted per
  client and per e-mail; a sign-in clears the e-mail's count, not the client's). Over the
  limit the page answers 429. Every refusal reads "Invalid email or password.": an unknown
  address, a wrong password, a disabled account and a config user alike. An unknown address,
  or an invited account without a password yet, costs a hash, like a known one. A record's
  sign-in stamps `last_login_at`.
- **Forgot password** (`{prefix}/forgot`, `folio.forgot`, 5 per hour). The answer is the
  same for every address. A mail goes only to an active record account.
- **Set password** (`GET {prefix}/password/{token}` shows the form, `POST {prefix}/password`
  consumes the token). It is throttled (`folio.password`). The minimum length is
  `config/folio.php` `password_min` (default 10). A used, expired or unknown link gets the
  same 410. Setting a password signs the account in, bumps its epoch, and revokes its
  remember-me logins and other links.
- **Mails** (invite and reset) are plain text, queued with preflow/notify after the
  response, in the site's default content locale (`folio.mail.*` in
  `lang/{en,de,fr}/folio.php`). The link is built from `app.url`. Without it no link is
  mailed: forgot logs it and answers as always, rbac's actions say "Set app.url to send
  sign-in links.". Staff mode needs `config/notify.php`, `APP_KEY` and `app.url`.

`Preflow\Folio\Users\StaffPasswords` (a container service) is the API for packages that
manage the accounts (preflow/rbac). It works for any record user type of the default guard:

- `invite()`: a link valid for 7 days, voiding earlier invites.
- `sendReset()`: a link valid for 1 hour.
- `consume()`
- `setPassword()`: saves on the record as stored now (only the password and epoch); false,
  with nothing saved, when the account is disabled or gone meanwhile.
- `verify()`
- `revokeLinks()`
- `forgetDevices()`
- `account()`
- `stampLogin()`: only `last_login_at`, on the record as stored now.

Records are saved without hooks or revisions.

**Revision authors.** Revisions store who saved them: the e-mail for a config user, the
record id for a record user. `Preflow\Folio\Users\AuthorNames` shows an id as the account's
name (else its e-mail), a disabled account included. It shows an e-mail or an unknown value
as stored. Without record users nothing changes.

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
for prefixed locales), or every visitor counts as signed out. The session cookie's path
(`session.path`) must cover the prefix as well. Access is granted when any signed-in user,
staff or member guard, has the permission. A share-preview link does not
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
"list": {
    "columns": ["title", "author", "status"],
    "filters": ["status", "author", "published_at"],
    "bulk": ["status"],
    "sort": "-title",
    "orderable": false
}
```

- `columns`: the fields shown. The default is the first three
  string/number/select/boolean/date fields that are not hidden. A `relation` field can be
  listed too: its cell shows the linked records' labels.
- `filters`: fields offered under "+ Filter": `select`, `boolean`, `date` (a from/until range)
  and `relation` fields. Active filters show as removable chips. A relation filter lists the
  target's records as a select, or searches them remotely when there are more than 200. Other
  field types and unknown names are ignored.
- `bulk`: `select` and `boolean` fields (not translatable, not read-only) that the bulk bar
  can set on the ticked rows ("Set Status"). Needs the type's edit permission.
- `sort`: the default order. A `-` prefix means descending. Without `orderable`, ties and
  unsorted lists end in the record id, so pages stay stable.
- `orderable: true`: up/down buttons that store a manual position in the record's
  `_order` key. New records go to the end.

Every list has a search box (all words must match, each in any text field or, one level deep,
in the label and text fields of a related record; a field with `"searchable": false` is left
out, and so is a hidden field unless it says `"searchable": true`), sortable headers for the plain columns (not relations), highlighted matches and paging
by 50. The view lives in the URL, so it can be bookmarked and shared:

| Parameter | Meaning |
|---|---|
| `q` | Search words (at most 200 characters). |
| `f[field]` | A filter from `list.filters`: a value, `f[field][from]` / `f[field][to]` for dates (`YYYY-MM-DD`), `f[field][]` ids for relations. |
| `sort` | A shown column, `-` prefix for descending. |
| `page` | 1-based page number, clamped to a sane range. |
| `_partial=1` | Only the results region, which the live search swaps in. |

Unknown fields, malformed values and out-of-range pages are dropped, never an error.

**Overriding `@folio/admin/list.twig`:** the page extends the layout and includes
`@folio/admin/_list_results.twig` for the swappable region. In a row, `row.cells[column]` is
the cell as plain text (escape it) and `row.marked[column]` is the same text as already
escaped HTML with the search matches in `<mark>` (print it with `|raw`). Use `list.headers`
for the sort links, `list.chips`, `list.clear`, `bulk` (null without any bulk action: no tick
column) and `export_url`. With the tick column the cell positions shift by one.
`row.extra` holds the cells of extension `#[ListColumn]`s, in their order after the type's
own columns (headings in `extra_columns`): each is plain text (escape it), a badge
`{label, tone}` or a link `{label, href}` (see Type customizations). Extension columns are
neither in the Excel export nor sortable. A site that overrides `_list_results.twig` must check
`cell.href` before treating an iterable cell as a badge.

At most 500 rows can be ticked for one bulk action or export (`RecordDeleter::MAX_IDS`): a
request with more `ids[]` is refused with a 422 and changes nothing.

The relation picker's search endpoint (`GET {prefix}/_relation/{type}?q=&label=&order=&page=`)
only uses a `label` / `order` field that a relation to that type declares as its `labelField` /
`orderBy`, and never a hidden field; it pages in the declared order, then by id.

#### Deleting: `onDelete` and the summary

A relation field can say what deleting its target does to the records that point at it:

```json
"author": { "type": "relation", "relation": { "to": "author", "onDelete": "restrict" } }
```

- `detach` (default): the link is removed from the pointing records.
- `cascade`: the pointing records are deleted too (recursively).
- `restrict`: the target can't be deleted while records point at it.

Deleting, single or bulk, goes through a delete plan, record by record on Folio's own save and
delete paths (so `#[BeforeDelete]`, `#[BeforeSave]` and the after hooks run for cascaded and
detached records). Before the confirmation the admin asks `POST {prefix}/{type}/_delete-plan`
and shows the summary: "This can't be undone." plus a line per type, such as "Also deletes 3
posts." or "2 posts lose a link."; for a restricted plan it shows the refusal ("Can't delete: 2
posts still point here.") instead. The summary is only information: the delete computes its own
plan. If any hook refuses, nothing is deleted. SQL writes run in one transaction per
connection; JSON-file writes can't be rolled back.

#### Bulk actions

The bulk bar appears when a row can be ticked: set fields from `list.bulk`, extension actions
and delete (each only for those who may use it).

```php
use Preflow\Folio\Extension\BulkAction;

#[FolioType('note')]
final class NoteTweaks
{
    #[BulkAction('archive', 'Archive', 'notes.archive', confirm: 'Archive the selected notes?')]
    public function archive(array $records, ServerRequestInterface $request): ?ResponseInterface
    {
        foreach ($records as $record) { /* ... */ }

        return null;
    }
}
```

`#[BulkAction(name, label, permission, confirm: null)]` on a `#[FolioType]` method, signature
`fn(list<DynamicRecord> $records, ServerRequestInterface $request): ?ResponseInterface`. It is
posted to `{prefix}/{type}/_bulk/{name}` with the ticked `ids[]`. It needs the permission and the
type's edit permission; the records are the ticked ones of this type that exist, in the order
posted. Returning `null` redirects back to the same list view with "{label}: done (N)"; a
response (a download, say) is sent as is; anything else is an error. The names `delete`,
`export` and `set-…` are Folio's own. Bulk delete needs the type's delete permission and is
all or nothing.

#### Excel export

With `openspout/openspout` (`^4`) installed (`composer require openspout/openspout`), lists get
an **Export** button: `GET {prefix}/{type}/_export` downloads an `.xlsx` of the whole filtered
and sorted list (not just the page), streamed in chunks of 500, or of the ticked rows when
`ids[]` are given. Columns are the list's own; numbers and dates are typed cells, text is never
written as a formula. Without OpenSpout there is no button and the URL is a 404.

#### Upgrading to 0.29 — notes for site owners

- Lists without `list.sort` or `orderable` now end in record-id order (on SQLite this replaces
  insertion order). Add `list.sort` where the order matters.
- The default `onDelete` is `detach`: deleting a record now rewrites every record pointing at
  it (types with publishing get unpublished working-copy changes, and `#[BeforeSave]` /
  `#[AfterSave]` hooks run). Declare `onDelete` explicitly where that matters.
- The list markup gained a tick column (`td.folio-bulk-cell`, an empty `th`) and sortable
  headers: site tests that read list columns by position need updating.
- `storage/cache/data` (the JSON query cache) must be writable and git-ignored.
- Mark secret fields of your own types (`hidden`) `"searchable": false` too; hidden fields are
  no longer searched by default, and Folio's own `password_hash` / `auth_epoch` fields say so.
- A field with `"hidden": true` listed in `list.filters` now makes the site fail at boot
  (`FrontendConfigException`), as one in `list.columns` does: remove it there or unhide the field.

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

`number` takes `"step": 0.5` (any positive number) or `"step": "any"` (since 0.46). The input gets that
`step`, and with a number the server checks that the value is numeric and a multiple of the step
(`2.3` is refused for `0.5`); `"any"` checks only numeric. Both checks judge a filled value only, so
an optional number field can be saved empty; with `"validate": ["required"]` an empty value gets the
required message alone. Without the option the browser takes whole numbers only and the server checks
nothing (as before). Any other value is a configuration error, and so is a step with more than 15
significant digits (`"0.1234567890123456"`): the check gets the step as a float, which holds no
more exactly, so it would compare against a different step. Both throw a `LogicException`. A float
step is read at 15 significant digits (`0.1 + 0.2` is `0.3`).

Any field also takes:

- `"hidden": true`: not shown in the edit form or the default list columns. A save keeps
  the stored value; a posted value is ignored.
- `"readonly": true`: shown in the edit form as its label and display value, without an input:
  a select its option label, a relation its record labels, a date formatted for the admin,
  rich text its sanitised markup. A save keeps the stored value; a posted value is ignored.
  A read-only field is not validated on save (not its `validate` rules, its type's rules or
  `required`): the editor can't change it, so checking it would only block the save.

- `"default": <value>`: the value a new-record form starts with (a singleton's form before
  its first save, too). A boolean `"default": true` shows checked on the create form.
  Since 0.27 this holds for every type, so a site whose models declare defaults now sees them
  prefilled on create forms (before, a create form started empty).

- `"unique": true` (or `"unique": ["scope_field", ...]`, unique within those fields), `"index": true`
  and `"length": n` (default 191; the column length and the character limit of a unique or
  indexed string, select or single relation): a duplicate value is refused on the field with
  "already taken", in the form, in autosave and in a draft save, also when two saves race and the
  database's unique index refuses the second. Empty values are never duplicates and are stored as
  `NULL`. A publishing type's `__published` snapshot gets a plain index, not a unique one. Autosave
  and draft saves skip the other rules but not what the storage refuses: a taken value or one longer
  than its column is answered in the save state ("Not saved — Slug: …") and nothing is saved.
  Restoring a revision or discarding changes whose value another record has taken meanwhile is
  refused with an error message on the editor, and the working copy stays as it was. See
  `preflow/data` "Unique fields and indexes".

`hidden` and `readonly` apply on create too: such a field starts empty unless a `#[BeforeSave]` hook sets it.

The admin display (read-only fields and list cells) is the field type's `renderAdmin()` when
it implements `Preflow\Folio\Field\DisplaysInAdmin`, otherwise its frontend output. A custom
field type can implement it to show a friendlier value in the admin than it stores. The
frontend output is never changed by it.

A `date` shows in the admin as `folio_date()` writes it in the interface language: "Oct 22, 2026"
(German "22.10.2026", French "22/10/2026"), or "Oct 22, 2026 23:15" with `"time": true`. Only
`Y-m-d`, `Y-m-d\TH:i` and ISO 8601 with an offset (shown in the app's timezone) are parsed; any
other value is shown escaped as it is stored, never as an error.

A field type says how its value is stored in a SQL column by implementing
`Preflow\Folio\Field\HasColumnKind` (`columnKind(array $config): Preflow\Data\Schema\ColumnKind`:
`Text`, `Short`, `Number`, `Boolean` or `Date`); Folio registers it with the data package. The
shipped types do (`number` is a number, `boolean` yes/no, `date` an ISO text, `string`, `select`,
`env` and a single `relation` are short when unique or indexed, otherwise text); a type without it
is stored as text.

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
- **Labels in the admin's language:** `label`, `group` and `description` of `#[Block]` and
  `label` / `help` of `#[BlockField]` may be translation keys (`'site.blocks.hero.label'`,
  delivered in `lang/{en,de,fr}/site.php`); plain text is shown as written.
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
- **relation:** references to records of one type. `"relation": {"to": "author", "multiple": true}`
  shows checkbox chips (a filter input above 12 options; they post `name[]`, as the old multi-select
  did); `"labelField"` picks the chip label, `"orderBy": "<field>"` sorts the options (default:
  stored order). A single relation stays a select. Above 200 records of the target the picker
  switches to remote mode: a search box above the chosen items, fed by
  `GET {prefix}/_relation/{type}?q=…&page=…` (`{items: [{id, label}], more}`), instead of
  listing every record. See [`onDelete`](#deleting-ondelete-and-the-summary) for what deleting a
  target does.

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
  again overwrites their change; reloading discards yours. The check is part of the
  write (`DataManager::saveTypeIfVersion()`), so two saves arriving at the same moment
  cannot both pass it.
- **Revisions:** every publish keeps a full copy in `{table}__revisions`, with the date,
  the user and the fields that changed. **Restore** copies one into the working copy,
  and it then has to be published again. Keep more or fewer revisions with
  `config/folio.php`:

  ```php
  'publishing' => ['revisions' => 20, 'preview_ttl' => 604800],
  ```

  `0` or `null` keeps every revision (a missing key keeps 20). Anything but a whole number
  of 0 or more (an int or a string of digits) or `null` stops the boot with an
  `InvalidArgumentException`: a negative number, a float, text or a bool. Each publish reads the record's revisions to number the next one, so unlimited
  history makes that read grow with every publish.

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
- Links work on every language tab (since 0.46): a German tab's link opens `/de/…`.
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

**Saving working copies in your own code:** `Publisher::saveDraft($record, $loadedVersion)`
saves without rules as version stored + 1 and throws `VersionConflict` (with `->current`,
the stored version) when the stored version is not `$loadedVersion`, also when another save
lands between the check and the write. With `null` it does not check and retries on a newer
version instead. A site form that saves a publishing type's record calls it with the version
its form loaded:

```php
try {
    $publisher->saveDraft($record, (int) $body['_version']);
} catch (VersionConflict $e) {
    // show the conflict; $e->current is the version to save against
}
```

`saveDraft($record, null)` can still throw `VersionConflict` in rare cases: when others save
the record during each of its three tries, or inside your own transaction, where the retries
reread the same snapshot.

**Publishing in your own code:** `Publisher::publish($record, $by, $loadedVersion)` validates,
then writes the working copy only if it is still stored with `$loadedVersion`, then writes the
snapshot (on SQL storage in one transaction). A stale version throws `VersionConflict` before
anything is written. The working copy is written as version stored + 1 when its content
differs from the stored one (any field but the id and Folio's `_` keys: an approve that sets a
status, an import), so an editor still on the old version conflicts instead of writing the old
content back; unchanged content (Folio's form: draft save, then publish; the scheduler) keeps
the version. Read the version from the record after `publish()`. Without the third argument
(`null`) the working copy is written unchecked (same version rule), as the scheduler,
`folio:publish` and importers need. Folio's own form publish passes the version its
draft save just wrote. `discard($type, $id, $loadedVersion)` and
`restore($type, $id, $revision, $loadedVersion)` check the same way (they bump the version).

```php
try {
    $publisher->saveDraft($record, (int) $body['_version']);
    $publisher->publish($record, $userId, (int) $record->get('_version'));
} catch (VersionConflict $e) {
    // someone saved in between; nothing was published
}
```

For a version-checked write outside publishing, use `DataManager::saveTypeIfVersion()` (see
preflow/data).

**States in your own code:** `->state($working)` reads the snapshot; `->stateWith($working,
$snapshot)` compares with a snapshot you already have (`null`: nothing published) and reads
nothing. `->states($type)` gives every working copy's state, `->states($type, $ids)` those of
some ids, each with one snapshot query (one per 500 ids).

**SQL storage:**
- Create `{table}__published` with the type's columns plus `_published_at` and
  `_published_by` (TEXT).
- Create `{table}__revisions` with `uuid` (primary key), `record_id`, `seq` (INTEGER),
  `data`, `changed`, `created_at` and `created_by`.
- Add `_version` (INTEGER), `_publish_at` and `_share_token` (TEXT) to the type's own
  table. A cleared schedule is stored as `''`, because the SQL drivers skip null values.
- JSON-file storage needs no setup.

## Asset library

The library itself (uploads, variants, private files, usage sources, `media_image()`) is the
`preflow/media` package: see its [README](../media/README.md). Folio adds the UI, installs the
package and binds its rules to the package's hooks. Files and records are stored as before:
`public/media/Y/m/{id}.{ext}`, the `folio_assets` table, `storage/private-media`, `/media/…`
and `/files/{id}`.

**Library screen** at `{prefix}/assets`:
- grid and list views;
- search by name, title or alt text, and filters by kind (images, videos, documents);
- multi-file upload;
- **asset details on their own page** at `{prefix}/assets/{id}`, with the tabs *Details* and
  *Edit image* (the second for editable images and `assets.edit`). *Details* has alt text,
  title and focal point (click the image to set it), and a poster image for videos. The page
  takes a `back` link to the library view it was opened from (search, filters, page); only
  a URL under `{prefix}/assets` is accepted, anything else falls back to the library. Since
  0.36 the request returns the full page also with `HX-Request`, no longer a fragment, so
  code that loaded the details panel with htmx must link to the page instead;
- **usage:** the records that use the asset, including published versions that still
  show it. Deleting a used asset is refused (by the media core).
- **replace** (permission `assets.edit`): the details page's *File* section takes a new file
  of the same kind; the asset keeps its id, alt text, focal point, extra fields and usage, so
  every record that shows it shows the new file. The current file is kept as a version;
- **earlier versions:** listed newest first with date, size and dimensions. *Open* shows the
  file (behind the private-file gate; staff only, see below), *Restore* (`assets.edit`) makes it current again (the
  current file becomes a version), *Delete* (`assets.delete`) removes it for good. Needs
  `versions_dir` (default `storage/media-versions`, a shared directory in deployments); see the
  media README, and `media:versions:prune` to trim old versions;
- **extra fields:** the fields declared in `config/media.php` `fields` appear on the details
  page under title, and the select, relation and boolean ones named in `filters` are filters next
  to the kind filter;
- **previews and colours:** a PDF shows its first page as thumbnail (needs Ghostscript
  10.03.1 or newer, or `pdftocairo`; `media:previews` makes them for existing files), and an
  image shows its dominant colour and palette as swatches: click a hex value or *RGB* to copy
  it (`media:colors --all` reads them for existing assets);
- **edit image** (permission `assets.edit`): the details page's *Edit image* tab is
  `{prefix}/assets/{id}/edit` for public JPEG, PNG, WebP and AVIF images. It rotates and
  mirrors, draws the image crop (free or with a ratio, including each box preset's), and
  lists every box preset (`height` and `fit` in `config/image.php`) with its cut: *Adjust*
  sets a format crop for a `cover` preset, *Reset* removes it. The original stays
  untouched; *Download original* is on the *Details* tab. The cropper is Cropper.js 1.6.2 (MIT,
  vendored in the package). A format crop stays inside the image crop; remove the image
  crop to art-direct outside it. The editing itself is the media core's (`rotate()`,
  `flip()`, `setCrop()`, `setFormatCrop()`); Folio only adds the screen;
- **notes:** an image that could not get colour-accurate variants (CMYK without Imagick) shows
  the asset's note on the details page.

**Asset fields** store asset ids. The editor shows the selection with **Choose**, which
opens the library as a picker in the drawer, and **Upload**, a plain file input that adds
the file to the library on save, so it works without JavaScript. A field's `accept`
(`image/*`, `video/*` or `.pdf,.zip`) limits what an upload may be. An asset field in a
type's default template renders with `media_image()`.

**Permissions.** `assets.view` (library and details page), `assets.upload`, `assets.edit` (details,
extra fields, replace, restore, edit image), `assets.delete` (delete an asset or an earlier version).

**Config.** Library settings (directory, widths, quality, private and versions directory,
extra fields, colour profile, …) live in
`config/media.php`. Folio reads two private-file settings from `config/folio.php`:

```php
'assets' => [
    'private_permission' => 'folio.dashboard', // default
    'private_login' => null,                  // optional: '/tools/login'
],
```

- Folio binds the media core's `PrivateFileAccess`: only a signed-in user with
  `private_permission` (staff with `*` pass), or with `assets.view` (staff who can see the
  library open what it shows), gets a private file or a private preview. It fails closed like
  the frontend gate: without auth configured, nobody does.
- **Earlier versions are staff only:** `{private_url}/{id}/v/{n}` of any asset, public or
  private, needs `assets.view`. `private_permission` never opens them, so members see only the
  current file.
- Anyone else gets a 403, or with `private_login` a redirect to `login?next={the file's URL}`
  (not for an earlier version: always a 403).
- A site's own `PrivateFileAccess` must be bound by a provider listed after Folio's in
  `config/providers.php`.

For a members area (with `private_url` set in `config/media.php`):

```php
// config/media.php
'private_url' => '/tools/files',
// config/folio.php
'assets' => ['private_permission' => 'members.access', 'private_login' => '/tools/login'],
```

A private asset field (`"private": true`) only takes private assets, and a public field only
public ones; its picker lists just those, and choosing the other kind is a field error.
Without the asset library, a private field refuses uploads. Export and pull carry the private
files with their asset records (archive root `private-media/`); an archive from before private
assets has no such root, and importing it leaves local private files alone. The earlier files
of replaced assets (`versions_dir`) travel the same way, under the archive root `media-versions/`.
Importing such an archive into a site without a `versions_dir` skips those files and says so in
the output (`Note: media-versions/: N earlier files skipped, …`).

```json
"pdf": { "type": "asset", "asset": { "accept": "application/pdf", "private": true } }
```

A refused upload in a field (wrong type, too large, not matching `accept`) shows as a field
error, and the record is not saved.

A content type keyed `assets` keeps working on the site, but its admin list is
hidden behind the library at `{prefix}/assets`. Rename the type to edit it.

**Usage.** Folio registers its record source with the media core; `AssetReferences` (the
scanner it uses) stays in Folio and is not deprecated.

**Older uploads:** paths stored before the library existed keep rendering through
`{prefix}/_uploads`. `php preflow folio:assets:import [--dry-run]` moves them into the
library:
- it rewrites every reference to them, in working copies, published versions,
  revisions and nested blocks;
- it lists files that are missing;
- running it again changes nothing.

**Dev server:** the PHP built-in server must serve `public/` as its document root for
`/media` files:

```
php -S 127.0.0.1:8000 -t public public/index.php
```

`public/index.php` should `return false` for existing files under `cli-server`.

**Deprecated in 0.34 (removed in a later release):**

| Old | New |
|---|---|
| `Preflow\Folio\Assets\Asset`, `Variant`, `AssetUploadException`, `ImageTag`, `ImageWidths`, `VariantGenerator`, `Image\*` | `Preflow\Media\…` (`Image\*` under `Preflow\Media\Image\`) |
| `Preflow\Folio\Assets\AssetLibrary` | `Preflow\Media\MediaLibrary` |
| `folio_image()` | `media_image()` |
| `folio_asset_url()` | `media_url()` |
| `config/folio.php` `assets` (library settings) | `config/media.php` (`private_permission` and `private_login` stay in `folio.php`) |
| `php preflow folio:assets:variants` | `php preflow media:variants` |

The old class names are aliases from `packages/folio/src/legacy-aliases.php`; the old
template functions and command still work. `config/folio.php` `assets` is read only when
`config/media.php` is missing, and never merged with it.

## Extensions

An extension adds screens, type customizations, models and settings to Folio without
touching Folio itself. A working one ships in
[`examples/folio-demo/app/Folio/Demo`](../../examples/folio-demo/app/Folio/Demo); the examples below are
copied from it.

**Where they live.** A project extension is a folder `app/Folio/{Dir}/` with an
`Extension.php` (class `App\Folio\{Dir}\Extension`). A package names its extension class
in `composer.json`: `"extra": {"folio": {"extension": "Vendor\\Pkg\\Extension"}}`. Folio
carries one itself: the members admin (`members`, in `src/Members/`), there when Composer lists
`preflow/members` (in `vendor/composer/installed.json`) and its classes load; it needs the `rbac`
extension enabled. A project's or package's own extension named `members` takes its place. Until
0.38 the admin was in the members package; an `installed.json` that still names
`Preflow\Members\Extension` is ignored, and `folio-extensions.cache` rebuilds by itself. With the
admin enabled Folio binds Settings → Members as the members core's `MembersSettings` and the
member's edit page as `MemberLinks` (the link in the owner's mails). Whenever `preflow/members` is
installed it binds its content locale as `PageLocale`, names `@folio/frontend/_site.twig` as the
member pages' default `layout` (`MembersDefaults`), and it also counts published snapshots in a member's own deletion
(`MemberDeleteCopies`) and carries the deletion into them (pointers cleared, cascaded pages
unpublished; the snapshot gets no revision entry).

The codes admin (`codes`, in `src/Codes/`) is Folio's too, there when Composer lists `preflow/codes`:
*Library → Codes* with the type `code`, the `codes_purpose` field, redemptions, expiry filters and
the *Revoke* action (permissions `code.view|create|edit|delete` and `codes.revoke`). Its texts are the
translation group `codes`, shipped by Folio. `preflow/codes` no longer needs Folio: it defines the type
`code` itself and Folio's richer definition takes its place when the extension is enabled; the storage
alias `codes` is optional (fallback `default`). The admin creates readable codes only; codes bound to a
recipient come from `Codes::issue()`. See the codes README.

The members admin also locks and unlocks a member (a Locked pill over the state, sessions ended, sign-in refused
while locked) and resends the confirmation mail to an unconfirmed one: the record actions `lock`, `unlock` and
`resend_confirmation` (`members.decide`), offered in the Decision panel where they apply, with "Locked since" and
"Last sign-in" lines. Needs `preflow/members` 0.39.

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

`prepareModel(array $schema, Container $c): array` sees each of the extension's model
schemas at boot, before a site's `"extends": true` file is merged, for what the JSON can't
know: a storage alias that falls back to `default` when the site has not configured it, or
select options from the site's locales.

**Enabling.** Nothing runs until `config/folio/extensions.json`, which is committed,
lists it:

```json
{"enabled": {"demo": {"disabled_parts": []}}}
```

Every part of an enabled extension is on unless listed under `disabled_parts`, with one
exception: a model whose JSON says `"opt_in": true` (and the extension's type part
`type:{key}` for it) stays off until the site lists it under `enabled_parts`, so a new
version can ship a model existing sites don't get unasked. Until then it is as if the
extension did not ship it: no type, no table, no permissions.

```json
{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}
```

`folio:extensions:enable <name> --part=<id>` and the Extensions screen write either list.
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
  required. The `label` may be a translation key (`'site.screens.queue'`): the sidebar item
  and `screen.label` then follow each staff member's admin language; plain text stays as written.
  `section` is `settings` by default; a new name adds a sidebar section after the built-in ones.
- A method returns a `ScreenView(template, data)` (rendered in the admin layout with
  `prefix`, `csrf` and `screen` added to your data), a string, or any PSR-7 response.
  POSTs need the CSRF token (`_csrf_token`, available as `csrf` in the template).
- `#[NavBadge]` on one public, non-static method of the screen (`fn(): ?int`, e.g. entries waiting for review)
  puts a count on its sidebar item. It runs only for a user who may see the screen, at most once per
  request; `null`, `0`, a non-int or a throw shows no badge. A static or second one is a boot error.
- A path colliding with a type key or a Folio path (`login`, `logout`, `_assets`,
  `_uploads`, `media`, `extensions`) is a boot error.
- The sidebar is data: `folio_nav()` in Twig, `Preflow\Folio\Http\AdminNav` in PHP
  (`add()` for your own items). Session flashes for the layout come from `folio_flashes()`.

### Screen assets

An extension ships its own CSS, JS and other files in an `assets/` folder next to its
`Extension.php` (`app/Folio/Demo/assets/`, or the package's extension folder). Folio serves
them without login at `{prefix}/_assets/x/{extension}/{path}`, for enabled extensions only
(a disabled or unknown extension, or any other path, is a 404). The URL Folio prints carries
`?v=` and the first 12 hex digits of the file's hash, so a changed file gets a new URL.

```php
#[FolioScreen('demo', 'Demo overview', permission: 'demo.view', assets: ['demo.css', '@folio/htmx.js'])]
final class Overview
{
    #[Get('/')]
    public function index(): ScreenView
    {
        // listed after the attribute's assets; a file named in both is included once
        return new ScreenView('@demo/overview.twig', [], assets: ['demo.mjs']);
    }
}
```

- **Where to list them:** `assets:` on `#[FolioScreen]` (every page of the screen) and on
  `ScreenView` (the one page). A `ScreenView` returned by a `#[FormPanel]` method adds its
  `assets` to the record's edit form. Attribute entries come first, order is kept and
  duplicates are dropped.
- **Entries:** `'file.js'` (own extension; the path may have subfolders, `'vendor/lib.js'`),
  `'@folio/htmx.js'` or `'@folio/trix'` (Folio's own files; these two are all `@folio/`
  offers; `trix` is `trix.css` plus `trix.js`), `'@other/file.css'` (the file of another
  enabled extension). A missing file, an unknown `@folio/` name or a disabled extension
  throws an `ExtensionException` naming the screen method or panel, so a file deleted after
  a deploy fails loudly instead of printing a dead tag.
- **htmx and modules:** use `@folio/htmx.js` rather than shipping your own htmx (only it
  gets the `htmx-config` nonce meta). ES modules must be named `.mjs`: a `.js` file with
  `import` loads as a classic script and fails.
- **Tags:** `.css` becomes `<link rel="stylesheet">` in the `<head>`; `.js` becomes
  `<script src defer nonce>`; `.mjs` becomes `<script type="module" nonce>`. Other served
  types (`map`, `svg`, `png`, `webp`, `woff2`, `json`) are not added to the page: load them
  from your JS. `@folio/htmx.js` also adds the `htmx-config` meta Folio's own htmx pages use
  (nonce for htmx's indicator styles, `allowScriptTags` off).
- **File rules:** only `css`, `js`, `mjs`, `map`, `svg`, `png`, `webp`, `woff2` and `json`,
  with a lower-case extension. Each path segment matches `[A-Za-z0-9._-]` and does not start
  with a dot. Anything else, a path leaving `assets/`, or a symlink pointing outside it is a
  404. Responses carry the right `Content-Type` and `X-Content-Type-Options: nosniff`.
- **Caching:** `Cache-Control: public, max-age=31536000, immutable` only when the request's
  `?v=` is the file's current hash. Any other request (no `?v=`, a stale one, or a lazy
  `import './x.mjs'`, CSS `url()` or `sourceMappingURL`) gets `Cache-Control: no-cache` and an
  `ETag`, and a matching `If-None-Match` is answered with 304, so edits show up at once.
- **No PHP in `assets/`:** extension discovery scans every `.php` file under an extension
  folder, `assets/` included, so a PHP file there is loaded as a class candidate. Keep PHP
  out of that folder (it is never served either way).
- **Strict CSP:** pages run under the nonce CSP. Folio's tags carry the nonce; do not add
  inline scripts, inline styles or `on*=` attributes in your templates.
- `folio_extension_asset('demo', 'logo.svg')` in Twig returns the versioned URL of a file
  (for an `<img>` or a preload link) and throws when it is not served.
- Edit-form panels (a `ScreenView` from a `#[FormPanel]`) get no `folio-extension-assets`
  meta: use `folio_extension_asset()` there. A site that overrides the admin layout
  (`@folio/admin/_layout.twig`) must print `page_assets` itself.
- Every extension screen page has `<meta name="folio-extension-assets" content="{prefix}/_assets/x/{extension}/">`
  in its head: the base URL for scripts that load more files at run time.

```js
// assets/demo.mjs: load Mermaid only when a diagram is on the page
const base = document.querySelector('meta[name="folio-extension-assets"]').content;
const nonce = document.querySelector('meta[name="csp-nonce"]')?.content;
const diagrams = document.querySelectorAll('.demo-diagram');
if (diagrams.length > 0) {
    const { default: mermaid } = await import(base + 'vendor/mermaid/mermaid.esm.min.mjs');
    mermaid.initialize({ startOnLoad: false });
    let n = 0;
    for (const el of diagrams) {
        const { svg } = await mermaid.render('demo-diagram-' + n++, el.textContent);
        const doc = new DOMParser().parseFromString(svg, 'image/svg+xml');
        // Mermaid's SVG carries a <style> element; the strict CSP needs the page nonce on it.
        doc.querySelectorAll('style').forEach((style) => style.setAttribute('nonce', nonce));
        el.replaceChildren(document.importNode(doc.documentElement, true));
    }
}
```

Copy Mermaid's whole `dist/` folder, `chunks/` included, to `assets/vendor/mermaid/`: the ESM
entry `mermaid.esm.min.mjs` imports many chunk files, so shipping the entry alone 404s. (Or
list the single-file `mermaid.min.js` as a classic script and use the `mermaid` global.)
Files imported at run time are not versioned (they revalidate on every load); change the
file name when you update them if you want long caching.

Mermaid writes inline `style` attributes and a `<style>` element into its SVG, and the
admin's strict `style-src` (nonce, no `unsafe-inline`) blocks both. The example stamps the
page nonce on the `<style>` element; inline `style` attributes can stay blocked, so check
the diagram in the browser (console, CSP violations) and prefer Mermaid themes that style
through the `<style>` element.

`editor_assets` (the template variable behind a field type's `assets()`) is internal to
Folio's own pages; extensions use the options above.

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
| `#[FormPanel(label, position: 'after')]` | `fn(DynamicRecord): ScreenView\|string\|null` | A panel on the edit form (not on create), `before` or `after` Folio's own extras, or `side`: a sticky column about 340 px wide to the right of the fields from 1280 px (on a previewable type in its plain form view; in split view the panels stack), stacked right under the form fields below that. A string is shown as escaped text, a `ScreenView` as a template fragment (it gets `prefix`, `csrf`, `type`, `id` and `record`). Returning `null` hides the panel (no heading, no box, and no side column or extras section on its account; an empty string still renders an empty panel). Any other position is a boot error. |
| `#[RecordAction(name, label, permission, icon, confirm, toolbar: true)]` | `fn(DynamicRecord, ServerRequestInterface): ?ResponseInterface` | A POST button at `{prefix}/{type}/{id}/_action/{name}`; null redirects back to the form with "{label}: done". Needs the permission and the type's edit permission. With `toolbar: false` there is no button in the form header; the action stays routed and permission-checked, for a form inside a `FormPanel` to post to. |
| `#[ListColumn(label, position: 100)]` | `fn(DynamicRecord): string\|Badge\|Link` | A column after the type's own, ordered by position, shown as escaped text, as a link for `new Link($label, $url)` (`$url` is `member/3/edit`, relative to the admin prefix, or a site path like `/shop/order?id=3`; anything with a scheme, host, `//`, backslash or whitespace is no link and shows the escaped label), or as a status pill for `new Badge($label, $tone)` (tone `''`, `published`, `draft`, `changed` or `incomplete`; the label is escaped, an unknown tone is shown plain). |
| `#[NavBadge]` | `fn(): ?int` | A count badge on the type's sidebar item (e.g. waiting members); also on a `#[FolioScreen]` class (see Screens), computed only while the item is visible and at most once per request; `null`, `0` or any non-int return shows none, a callback that throws shows no badge (the admin still renders), several add up, one per class. Keep it cheap: it runs on admin page renders. |
| `#[FieldPlaceholder(field)]` | `fn(string $locale): ?string` | What the field means while it is empty, as its placeholder in the edit form in the locale being edited (e.g. a standard text from the lang files); it replaces the "falls back to" placeholder of a translatable field. The field must exist on the type (boot error), one per field. |
| `#[BeforeSave]` | `fn(DynamicRecord): void` | Before validation; may `set()` fields. Throw `Preflow\Validation\ValidationException` to refuse the save: the form is shown again with its errors (422). |
| `#[AfterSave]` | `fn(DynamicRecord): void` | After the record is stored. |
| `#[BeforeDelete]` | `fn(DynamicRecord): void` | Throw `Customization\Refused('message')` to keep the record; the message is shown to the editor. |
| `#[AfterDelete]` | `fn(DynamicRecord): void` | After the record is gone. |

A decision panel in the side column, with its own form and tabs:

```php
#[FormPanel('Decision', position: 'side')]
public function decision(DynamicRecord $record): ScreenView
{
    return new ScreenView('@members/decision.twig');
}

#[RecordAction('accept', 'Accept', 'members.decide', toolbar: false)]
public function accept(DynamicRecord $record, ServerRequestInterface $request): null { /* ... */ }

#[RecordAction('decline', 'Decline', 'members.decide', toolbar: false)]
public function decline(DynamicRecord $record, ServerRequestInterface $request): null { /* ... */ }

#[ListColumn('State')]
public function state(DynamicRecord $record): Badge
{
    return new Badge('Waiting', 'changed');
}
```

```twig
{# decision.twig: one form per tab panel, each posting to its own action #}
<div data-folio-tabs>
    <div class="folio-segmented">
        <button type="button" data-folio-tab="accept">Accept</button>
        <button type="button" data-folio-tab="decline">Decline</button>
    </div>
    <div data-folio-tabpanel="accept">
        <form method="post" action="{{ prefix }}/{{ type }}/{{ id }}/_action/accept">
            <input type="hidden" name="_csrf_token" value="{{ csrf }}">
            <textarea name="message" rows="6"></textarea>
            <button type="submit" class="btn btn-primary">Accept and send</button>
        </form>
    </div>
    <div data-folio-tabpanel="decline">
        <form method="post" action="{{ prefix }}/{{ type }}/{{ id }}/_action/decline">
            <input type="hidden" name="_csrf_token" value="{{ csrf }}">
            <textarea name="message" rows="6"></textarea>
            <button type="submit" class="btn btn-primary">Decline and send</button>
        </form>
    </div>
</div>
```

Use one form (and one `toolbar: false` RecordAction) per tab, so a click on a send button
says which decision was made and only that panel's fields are posted.

Tabs: `<div data-folio-tabs>` holds a `.folio-segmented` of `<button data-folio-tab="key">` and
sibling `<div data-folio-tabpanel="key">` panels. admin.js adds the ARIA roles, arrow keys
(Home/End too) and hides the inactive panels; the first tab is active unless one carries
`data-folio-tab-active`. Panels are focusable. Without JS every panel shows, stacked, and the tab buttons stay hidden.

Things to know:

- **Hooks** run on the admin store, update (plain and publishing types) and destroy. They
  do **not** run on reorder, nor on the publishing controller's restore, discard or autosave.
- **Record actions:** a click submits only the action's own form, so unsaved edits in the
  edit form are not saved. On a publishing type the action receives the working copy;
  saving it directly (as above) bypasses the Publisher's versioning, so changes appear in
  the next publish rather than as a revision.
- Duplicate action names on a type are a boot error. A disabled `type:{key}` part has no
  effect.

### Extending an extension's type

A site adds fields to a type an extension ships (and to its `{name}_settings` singleton)
without forking it: a model file in `config/models/` whose key is that type and which says
`"extends": true`.

```json
{
    "key": "member",
    "extends": true,
    "fields": {
        "company": { "type": "string", "label": "Company" },
        "role_title": { "type": "string", "label": "Role", "after": "name" }
    },
    "list": { "columns": ["name", "email", "company"] }
}
```

- `fields` are appended after the extension's; `"after": "name"` on a field puts it right
  after that field instead (the field must exist, in the extension or earlier in the file).
- `list.columns`, when given, replace the extension's columns; its other `list` options stay.
- `storage`, `table`, `users`, `id_field` and `key` are locked: repeating the extension's value
  is fine, a different one is a boot error naming the option. So is redefining a field the
  extension defines. Any other key (`label`, `icon`, `section`, a `list` option other than
  `columns`...) is a boot error too, as the merge would drop it; each error names the file.
- The patch is never a type of its own; `folio.tables` (`preflow migrate`) adds the new
  columns. A patch no enabled extension type takes is a boot error that names the file and,
  when the extension is switched off, the command that enables it.
- `folio:extensions:disable` (and the admin toggle) refuse to disable an extension or part a
  patch extends, so the site never ends up unbootable. Remove the file first. If it happens
  anyway, `preflow folio:extensions:enable <name>` still works (it enables on discovery alone
  when the boot fails on such a patch), and `disable` works without booting.

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
$settings->get('demo', 'greeting', 'de');    // translatable fields: a locale (falls back like content)
$settings->get('demo', 'greeting', 'de', exact: true); // only de's own value or default, never another locale's
```

- Before the first save the form and `get()` return the declared defaults.
- Permissions are the settings type's standard ones: `{name}_settings.view` and
  `{name}_settings.edit`.
- Only `null` (never saved) counts as "not set": a cleared string reads `''`, and an
  unchecked boolean reads `false` on every storage, also where SQL hands it back as `''`.
- An unknown extension or key throws in debug and returns null in production.
- **Secrets stay out of settings.** A field of `'type' => 'env', 'env' => 'MAIL_PASSWORD'`
  stores nothing and shows only "set" or "not set"; `get()` reads the environment variable.

## Cache screen

*Settings → Cache* (`{prefix}/cache`, permission `folio.cache`; staff with `*` have it) lists
every cache registered with `Preflow\Core\Cache\CacheRegistry` as a table row: label,
entries, size and a **Clear** button, plus **Clear all**. Folio registers `folio.extensions`
and `folio.blocks`; core registers `data.json` (when the JSON driver is in use) and `blade`
(when the Blade engine is in use). A site registers its own:

```php
$app->container()->get(\Preflow\Core\Cache\CacheRegistry::class)
    ->register(new \Preflow\Core\Cache\DirectoryCache('images', 'Resized images', $app->basePath('storage/cache/images')));
```

An OPcache table shows the status (memory, scripts, hit rate) with **Reset OPcache**. OPcache
is per SAPI: the web request resets the web pool's cache; the CLI cannot see it, so the
button is a web-only action. The same registry backs `cache:list` and
`cache:clear <key>… | --all` (devtools).

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

**Signing users in.** `Preflow\Auth\Record\RecordUserProvider` is a user provider over a
user type, for a `SessionGuard` in `config/auth.php`:

```php
'providers' => [
    'members' => [
        'class' => Preflow\Auth\Record\RecordUserProvider::class,
        'type' => 'member',
        'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles',  // the defaults
    ],
],
'guards' => [
    'members' => ['class' => Preflow\Auth\SessionGuard::class, 'provider' => 'members'],
],
```

- The class lives in preflow/auth since 0.38; Folio builds it with its type catalog. The old
  name `Preflow\Folio\Users\RecordUserProvider` (and `Preflow\Folio\Users\RecordUser`) works for
  one release; a config naming it logs a deprecation.
- The type must exist and have `"users"` set, or boot fails with a message naming it.
- The Folio login upgrades an outdated password hash (an imported `$1$` hash, an older cost) at
  sign-in, without touching `auth_epoch` (`Preflow\Auth\PasswordRehash`, see auth's README).
- `findByCredentials()` lowercases and trims the typed email and accepts only an exact match
  of the stored value, and only when exactly one record has it: a database that compares
  loosely (MySQL collations) never lets a look-alike or duplicate pick an account. Store
  emails lowercase.
- The record is the signed-in `RecordUser`; its `roles` field (a list or a JSON/comma
  string) feeds the permission resolver.
- Its `auth_epoch` field (provider key `epoch`; `"0"` when the type has none) is the
  session epoch (`Preflow\Auth\HasSessionEpoch`): when it changes, the user's other
  sessions are signed out.
- When the default guard has record users (directly or in a `ChainUserProvider`), a
  signed-in record user has an own account page at `{prefix}/_account` (linked from the user
  menu, no permission needed; a config user has none): it changes the password only, asks
  for the current one, is throttled (`folio.password`, 10 per 15 minutes per account) and
  signs out the account's other sessions, remember-me logins and invite/reset links
  (`StaffPasswords::setPassword()`). `StaffPasswords` also offers `revokeLinks()`,
  `forgetDevices()` and `verify()` for packages that manage the accounts (preflow/rbac).

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
  `TEXT PRIMARY KEY`; every other column is nullable and typed by its field (numbers and yes/no
  as numbers, everything else text; unique and indexed fields get their indexes), see
  `preflow/data` "Record tables". `__revisions` stay text. A site made before 0.42 keeps its text
  columns until it is converted with `tools/convert-typed-columns.php`; `preflow migrate` warns
  about them meanwhile.
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
  revision copies), the asset records, the media directory and legacy uploads. Assets in the
  media bin stay on their server (their files are in its bin only): an export leaves them out
  and an import or pull leaves the local ones alone.
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
- **Records all or nothing:** the records are replaced in one go (removed and replaced rows
  first, so a slug that has another id live never collides); if that fails, no record changes.
  A replaced record is written whole: on SQL, as on JSON, a column only the local models have is
  empty after an import or pull.

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
- The list shows a badge per language: `de · 2 missing`, `de · 1 outdated` or just `de`
  (see "Missing and outdated" below). The form's language tabs carry the same counts.

**Website:**
- `/` and `/about` serve the default language; `/de/…` serves German, with translated
  route values (e.g. `/de/ueber-uns`).
- A page not visible in a language returns 404 there.
- Missing translated values fall back field by field: requested, then fallback, then
  default.
- Templates receive `locale` and `alternates` (`[{locale, url}]`).
- `{{ folio_alternate_links(alternates) }}` emits the `hreflang` links (including
  `x-default`).
- The absolute URLs in `alternates` (`href`, used for `hreflang` and canonical links) use
  the host of `app.url` (`config/app.php` `url`) when it is set and absolute, so a request
  on `www.` or another alias still names the one canonical host. Without it they use the
  request's scheme and host.
- `{{ folio_url('page', id, 'de') }}` links to a record in a language.
- Referenced records (relation, matrix) render in the page's language.

**Validation:** `required` applies to the default language only. Other rules apply to
every language that has a value; errors are keyed `field.de`.

Older values stored before a field became translatable keep working as the default
language's value. Upload fields are always shared.

### Missing and outdated

Folio checks every translatable text of a record: translatable fields, the translatable
fields of blocks (also nested ones) and the texts a custom field type reports.

- **Missing:** the default language has a text and the language has none. Every such text
  counts, required or not. Whitespace only is empty.
- **Outdated:** the translation was saved from a default text that has changed since.
  Saving a translation stamps the fingerprint (a short hash of the trimmed default text) in
  the record's `_translated_from` (`{locale: {path: fingerprint}}`). A translation without a
  fingerprint (saved before 0.45, imported) is never outdated. A single-locale app has
  neither.
- **In the admin:** the list badge and the form's language tabs show the counts. On a
  language's tab an outdated field is marked, with a **Still correct** button that stamps
  the current default text without changing the translation (for a field with block texts:
  "3 texts outdated", one button for all of them). That saves the working copy as a draft
  (a published record becomes "changed"); nothing is published, and a stale `_version` is a
  conflict like any save. The route is `POST {prefix}/{type}/{id}/_translations/confirm`
  (`locale`, optional `path`, `_version`) and needs the type's edit permission.
- **Publishing:** outdated never blocks. With `'publishing' => ['complete_languages' => true]`
  in `config/folio.php` a record is not published while a language it is visible in
  (`_locales`) misses a text; the draft is saved and the error names the language and the
  texts (`_translations.de`). Off by default. Scheduled publishing reports such a record
  as failed. Machine translations nobody has checked yet (see `unchecked()`) show as a note
  with an accept button per field, a count on the language tab and a badge in the list (it
  links to the list narrowed with `?unchecked=1`). They never block publishing unless
  `'publishing' => ['checked_translations' => true]` is set: then the publish is refused
  (`_unchecked.de`); without it the editor is warned after publishing. Off by default.
- **New column:** `_translated_from` is a TEXT column on SQL-backed record tables (and their
  published and revisions tables); `preflow migrate` adds it.

**Custom field types.** A field type that keeps locale maps inside its own value tells Folio
about them by implementing `Preflow\Folio\Field\ReportsTranslatableTexts`. Paths and labels
are relative to the field; Folio prefixes them with the field's name:

```php
final class FaqFieldType implements FieldType, ReportsTranslatableTexts
{
    // value: [['question' => ['en' => '…', 'de' => '…'], 'answer' => [...]], ...]
    public function translatableTexts(mixed $value, array $config, Locales $locales): array
    {
        $texts = [];
        foreach ((array) $value as $i => $item) {
            $texts[] = TranslatableText::of("{$i}.question", 'Question ' . ($i + 1), $item['question'] ?? null, $locales);
        }

        return $texts; // reported as "faq.0.question"
    }
}
```

Use a stable key in the path (an id, not the position) when items can be reordered, so a
fingerprint follows its item.

**Taking part in AI translation.** Reporting texts is only half of it. To let AI translate the
texts of your field, the field type must also implement
`Preflow\Folio\Field\AcceptsTranslatedTexts`, the way back: Folio hands it translated texts by
path and it returns the field value with them set. Only the field type knows how its value is
built, so only it can do that.

```php
final class FaqFieldType implements FieldType, ReportsTranslatableTexts, AcceptsTranslatedTexts
{
    // translatableTexts() as above. $texts is path => text, with the paths you reported ("0.question").
    public function withTranslatedTexts(mixed $value, array $texts, string $locale, array $config, Locales $locales): mixed
    {
        $items = (array) $value;
        foreach ($texts as $path => $text) {
            [$i, $key] = explode('.', (string) $path, 2) + [1 => ''];
            if (isset($items[(int) $i][$key])) {
                $map = LocaleValue::map($items[(int) $i][$key], $locales);
                $map[$locale] = $text;
                $items[(int) $i][$key] = $map; // only this language changes
            }
        }

        return $items;
    }
}
```

Set only `$locale`, leave every other language and every other text as it is, and ignore paths
you do not know. A field type that reports texts but does not implement `AcceptsTranslatedTexts`
is left out of AI translation, so no money is spent on a text that could never be stored. A
text may also carry a kind (`TranslatableText::of($path, $label, $raw, $locales, 'faq-answer')`),
which lets tuning target it (see "Field options" below).

**SQL storage:** translatable values and the record's `_locales` list are stored as JSON
text. A SQL-backed type needs a `_locales` (TEXT) column, and its translatable columns
must be wide enough for the JSON map. JSON-file storage needs no setup.
`folio_alternate_links()` emits absolute URLs when the request has a host, which
search engines require.

### Translate with AI

With AI on (`config/ai.php` `enabled` and the provider's key, see `preflow/ai`), each language
tab except the default gets a **Translate** button, for users with the `ai.translate`
permission ("Use AI translation") and the type's edit permission. With AI off there is no
button, the form's markup is unchanged and the endpoints below answer 404.

- The dialog asks which texts (**Missing and outdated**, or **All**) and an optional one-off
  note, and shows the estimate for this record.
- **Translate** fills the form's fields: top-level fields, block texts (also nested ones) and
  the texts of custom field types. Rich text is filled through its editor. Nothing is saved;
  the autosave picks the change up like typing.
- Under each filled field a review row shows the default-language text, the previous
  translation with the changed words marked, and warnings (e.g. a glossary term not used).
  **Accept** keeps the text as checked, **Discard** puts back what the field held right before
  the fill (unsaved typing included). Editing the AI text counts as checking it. **Accept all**
  is in the dialog.
- On save, texts filled by AI, not accepted and not edited are saved as not checked (the form
  posts their paths as `_ai_unchecked[]`). Accepted ones are posted as `_ai_checked[]`, which
  clears their mark, also one the autosave set before you accepted.

The endpoints, for other admin screens (both POST, with the CSRF token, never writing):

- `{prefix}/{type}/{id}/_ai/estimate` with `locale`, `scope` (`needed` or `all`) and an
  optional `prefix`: `{units, requests, costUsd}` (`costUsd` is null when the model has no
  price).
- `{prefix}/{type}/{id}/_ai/suggest` with `locale`, `scope`, optional `note` and `prefix`:
  `{texts: {path: {text, source, previous, warnings}}, failed: {path: reason}, costUsd}`
  (`costUsd` is null when a request's model has no price; the run screen then shows "—").
  `prefix` limits the texts to paths under it (`flow` gives `flow` and `flow.…`). When the
  provider cannot be used at all (quota, billing, outage) it answers 503 with `{error}`.

Without `ai.translate` they answer 403. The `ai.settings` permission ("AI settings") is
declared too.

#### Translating a whole type

Under the same conditions the list has **Translate all…** in its header and **Translate…** in
the bulk bar (the ticked records). The run screen asks for the languages, the texts (needed or
all) and a note, then shows the plan: "N records, M texts, K requests, about $X". **Start** runs
the job one record per request while the page shows the progress (done / total, the cost so
far, each record's status, failed and changed-meanwhile records with their reason). **Stop**
keeps what is done; **Continue** picks up the rest, also after the provider stopped the run
(quota, billing), whose message is shown.

- The page has to stay open while the job runs: each step is a request from the page, and
  there is no background worker, so the run only moves while this page is open. **Stop** keeps
  what is done, and **Continue** picks up the rest. A run whose page was closed, or whose last
  step never came back, is not lost: the run screen (**Translate all…**) lists the type's
  unfinished runs (not started, left running, stopped; the newest five) with **Continue**. When a
  step fails while the page is open (the connection dropped, the server ended the request), the
  progress says "The last step didn't finish" and offers **Continue** in place. The state is kept
  in `storage/ai/jobs/`.
- Each suggestion and step request keeps running when the browser goes away
  (`ignore_user_abort`) and raises a shorter PHP time limit to 300 seconds where the host allows
  it; the provider clients give up on a request after 120 seconds.
- Publishing types get working copies, with the texts marked as not checked; nothing is
  published. Records whose working copy already had other changes are listed separately.
  An AI write never publishes, never changes a record's **Visible in** languages, and cancels a
  pending **Publish at** (as a save by someone who may not publish does), so machine text cannot
  go live on a timer without a person looking at it.
- A type without publishing writes live: Start stays disabled until "the translations go live
  without review" is ticked, and the server refuses a start without it.
- The results end with "Review: N records with unchecked texts", a link to the list filtered
  to records with unchecked texts.

The routes (all ask `ai.translate` and the type's edit permission; POSTs need the CSRF token):
`GET|POST {prefix}/{type}/_ai/run` (form; `step=estimate` plans the job), and
`POST {prefix}/{type}/_ai/jobs/{job}/start|step|stop`, which answer the progress partial for
htmx and the whole screen otherwise. A job is only reachable through the type it translates.
Jobs are stored in `storage/ai/jobs/{id}.json`.

#### Field options

A model's field can tune or leave out AI translation with an `ai` block in its JSON:

```json
"body": { "type": "richtext", "translatable": true, "ai": { "tuning": "Keep the tone playful." } },
"faq": { "type": "faq", "ai": { "kinds": { "faq-answer": "Short; no full stop." } } },
"internal_code": { "type": "string", "translatable": true, "ai": { "translate": false } }
```

- `translate: false` leaves the field out of AI translation (button, whole-type runs, estimate).
  You can still translate it by hand. Only the model JSON sets this; the Settings screens cannot
  override it.
- `tuning` is an instruction for this field (a string, or a list of strings).
- `kinds` maps a text kind to an instruction. A structured field of your own (see
  "Custom field types" above) can tag each text with a kind, for example `faq-answer`, and the
  instruction then applies to those texts only.

These are the defaults. A text saved in Settings → AI → Tuning under the key `{type}.{field}`
or `{type}.{field}:{kind}` replaces the default of that level and leaves the others alone.

#### Settings → AI

The **AI** entry in the Settings section needs the `ai.settings` permission. It stays visible
while `enabled` is true even without a key, so an admin can see why nothing works ("switched
on: yes, key: no"). It has three screens:

- **Status:** provider, model, whether a key is present (never the key), a **Test** button that
  sends one tiny request, and the calls, tokens and cost of this and last month per purpose and
  model. A model with no price shows "—", not 0.
- **Tuning:** the instructions per level: project, each language, each translatable type, and
  any field or kind level you add by key. The config default shows as the placeholder; saving a
  text replaces the default of that level, and saving it empty brings the default back.
- **Glossary:** the fixed terms with their rendering per language, a "keep as is" choice, the types
  they are limited to and a note.

Edits are stored in `storage/ai/settings.json`, apart from `config/ai.php`.

#### Labels and media

Folio's own pieces get the same button, with the same review and the `ai.translate` permission:

- **Settings → Translations:** a **Translate** button for the shown page of labels fills empty
  values as unsaved input (or every value with "all"). Review them, then **Save all filled**
  saves every filled or changed row of the page in one go (a row's own Save button still saves
  just that row and reloads the page). A text with markup or wrong placeholders is refused on its
  own: it stays in its field with the reason under it, the others are saved. While filled rows
  are unsaved, leaving the page asks first.
- **Media:** the asset panel has a button for the **alt text** of each language, translated from
  the default-language alt text. Titles are not translated, because an asset has one title for
  all languages.

Labels and alt texts have no "outdated" or "not checked" tracking: their stores keep no
fingerprints, and both screens are reviewed in full anyway.

#### Trying it without a key

Bind an `Ai` whose client is `Preflow\Ai\Testing\FakeAiClient` in a provider listed after
Folio, on your machine only. This one answers every text with "[de] " and the text:

```php
// app/FakeAiProvider.php (local only; list it after FolioServiceProvider in config/providers.php)
final class FakeAiProvider extends \Preflow\Core\Container\ServiceProvider
{
    public function register(\Preflow\Core\Container\Container $container): void
    {
        $container->singleton(\Preflow\Ai\Ai::class, static fn ($c) => new \Preflow\Ai\Ai(
            ['enabled' => true, 'provider' => 'claude', 'models' => ['claude' => 'claude-sonnet-5-5'], 'keys' => ['claude' => 'FAKE']],
            $c->get(\Preflow\Ai\Usage\UsageLog::class),
            $c->get(\Preflow\Ai\Usage\Pricing::class),
            static fn (): string => 'fake', // the "key"
            static fn () => new \Preflow\Ai\Testing\FakeAiClient([static function (\Preflow\Ai\AiRequest $r) {
                $items = json_decode(substr($r->prompt, (int) strrpos($r->prompt, "\n")), true); // the texts: the prompt's last line

                return \Preflow\Ai\Testing\FakeAiClient::json(array_map(static fn (array $i) => ['n' => $i['n'], 'text' => '[de] ' . $i['text']], $items));
            }], 'claude-sonnet-5-5'),
        ));
    }
}
```

Then open a record's German tab, click **Translate**, review (accept one text, edit
another, discard a third), save, and reopen the tab: only the AI texts you left as they were show
"Machine-translated, not checked yet".

#### Upgrading to 0.47

**SQL sites: run `preflow migrate` after upgrading.** Every save in a second language now
stores the source text next to the fingerprint (`_translated_source`) and the machine-made marks
(`_machine_translated`), also with AI off. On SQL storage these are new TEXT columns on each
record table and its published and revisions tables; until `preflow migrate` adds them,
publishing a record and saving a translation fail. JSON-file storage needs nothing.

## Admin languages

Folio's own screens, the staff mails and the admin parts of rbac, members and codes come in
English, German and French. Each staff user picks the language of the admin for themselves;
the content languages (`config/i18n.php`) are separate.

**Choosing the language:**
- The sidebar has a language switch next to the theme switch, and the account page a
  *Language* panel. The choice is stored per user (table `folio_user_preferences`; a config
  user under its address) and applies on every device.
- Without a choice, the admin uses `config/folio.php` `'locale'`, else `app.locale`, else
  English when Folio has no texts in that language.
- The sign-in and password pages follow the browser (`Accept-Language`).
- Staff mails (invitation, password reset) go out in the recipient's language, else the site's
  default content language.
- The languages offered are the ones Folio has texts for: its own `en`, `de`, `fr`, plus any
  `lang/{locale}/folio.php` a site adds (two-letter codes).
- Public pages never change with the admin's language: the translator's locale and the
  overrides are put back after every admin request.
- Media's alt texts and captions follow the content language, not the admin's: Folio binds
  media's `LocaleProvider` to its content locale. A site that binds media's
  `ConfigLocaleProvider` itself is outside that wiring: it reads the translator's locale, which
  on an admin request is the admin's language.

**Changing a text: the Translations screen.** *Settings → Translations*
(`{prefix}/translations`, permission `translations.edit`) lists the texts of every `admin`
group (Folio, rbac, members-admin, codes, the extensions' groups) and the site's model labels,
per language. A changed text replaces the delivered one in the admin for everyone using that
language; *Reset* brings the delivered one back.
- Plain text only: `<`, `>`, `"`, `` ` `` and HTML entities (`&#…`, `&name;`; a plain `&` is
  fine) are refused, and texts whose English contains markup
  are not offered. A straight `'` is stored as `’`.
- Formats (`folio.format.*`) are configuration, not offered.
- The texts live in the table `folio_translation_overrides` (run `preflow migrate` on SQL
  storage), are compiled to `storage/cache/folio-translations-{locale}.php` (Settings → Cache
  lists it) and travel with `folio:export` / `folio:import` / `folio:pull`.
- A site's own `lang/{locale}/folio.php` (or `rbac.php`, …) changes single keys for every
  request, in version control; an override on the screen wins over it.
- Overrides apply on admin requests only, except editable site groups (below). Staff mails sent from a console command or a queue
  worker (outside an admin request) use the delivered texts and the site's `lang/` files.
- A site or package that sets its own `TranslationOverrides` on the translator keeps it: Folio
  only fills an empty override layer (`NoTranslationOverrides`), and the Translations screen's
  texts then don't apply.

**Editable site texts.** A site's own text groups that staff should change themselves (a
mail's subject, a notice on a page) are listed in `config/folio.php`:

```php
'translations' => ['editable' => ['review-mail']],
```

The screen offers each listed group besides the admin groups, in every language of
`config/i18n.php` (not the admin's languages), with the note that the texts appear on the
website and in mails. Its overrides apply on every request (`Translator::alwaysOverride()`):
public pages, mails in the recipient's language (`t($key, $params, 'fr')`) and console or
queue mails. Plain text as above, and an override keeps exactly the `:placeholders` of the
delivered text (the language's, else the fallback language's, else English: what visitors
and mails get today, shown as the delivered text): dropping `:link` or adding an unknown `:foo`
is refused; a colon followed by letters counts as a placeholder (`:name`), so a text like `mailto:info@…` is checked as one. The keys are those of `lang/{locale}/{group}.php` in every site language (only two-letter languages are offered; region locales like `de-CH` are not listed). An
entry that is not a group name, `site` (the model labels stay admin-only) or an `admin` group
(`folio`, `rbac`, …) is skipped with a line `folio: translations.editable: …` in the error
log; boot goes on, and every group not listed renders exactly as before.

**Model labels.** A model file's `label`, `label_singular`, a field's `label` and `help` and a
select option's label can be a plain string or a map per language:

```json
{ "key": "app", "label": {"en": "Apps", "de": "Anwendungen", "fr": "Applications"},
  "label_singular": {"en": "App", "de": "Anwendung", "fr": "Application"},
  "fields": { "title": { "type": "string", "label": {"en": "Title", "de": "Titel"} } } }
```

Or keep the plain English string and add keys to `lang/{locale}/site.php`:

| Text | Key |
|---|---|
| type label / singular | `site.types.{type}.label` / `site.types.{type}.singular` |
| field label / help | `site.fields.{type}.{field}.label` / `site.fields.{type}.{field}.help` |
| select option | `site.options.{type}.{field}.{value}` |
| sidebar section (also a section only a screen names) | `site.sections.{section}` |
| extension screen label | `site.screens.{name}` (`#[FolioScreen('queue', 'site.screens.queue')]`) |
| empty list sentence | `site.types.{type}.empty` (optional) |

A key wins over the map, the map over the plain string. They show in the sidebar, the
dashboard, list headers, filters, chips, bulk menus, the Excel header row, the editor and the
roles screen; public pages are unchanged. `label_singular` gives "1 Seite" in the delete
summary; without it English guesses and other languages use the label.

A project's own extension (in `app/Folio`, no `translations:` group) uses `site.*` keys for its
attribute labels too: `#[ListColumn('site.columns.kind')]`, `#[FormPanel('site.panels.check')]`,
`#[RecordAction(label: 'site.actions.go')]`.

**Extensions.** An extension that ships texts declares its group in its package's
`composer.json` (see [`preflow/i18n`](../i18n/README.md#package-translations)) and names it on
the extension: `#[FolioExtension(name: 'shop', …, translations: 'shop')]`. Then:
- its types' labels resolve under `shop.types.…`, `shop.fields.…`, `shop.options.…` (same
  shapes as above), its settings type too;
- attribute labels may be keys: `#[ListColumn('shop.list.state')]`,
  `#[RecordAction(label: 'shop.actions.refund', confirm: 'shop.actions.refund_confirm')]`,
  `#[FormPanel('shop.panels.orders')]`. Any text with spaces or capitals stays as written;
- its permissions show as `shop.permissions.{rest}` on the roles screen (for `shop.refund`:
  `shop.permissions.refund`) and its name as `shop.extension.label`;
- `translations:check` reports a delivered language missing one of these keys.

In templates, `folio_date(value, 'date'|'datetime')` and `folio_number(value)` format in the
admin's language (German `26.10.2026`, French `26/10/2026`, English `Oct 26, 2026`); `t()` reads
any key. **Block labels** may be keys too: `#[Block(label: 'site.blocks.hero.label', group:
'site.blocks.groups.sections', description: 'site.blocks.hero.description')]` and
`#[BlockField(label: 'site.blocks.hero.title', help: …)]` are shown in the admin's language
(block picker, cards, field labels and help, the preview's block label) from
`lang/{locale}/site.php`; plain text stays as written.

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
- the asset library (variants, `media_image()`, picker, usage, legacy import);
- click-to-edit in the live preview;
- `folio:pull` (live content to local);
- permissions on every admin action (`can()`, with `preflow/roles` or `preflow/rbac` for roles);
- the admin in English, German and French, chosen per user, with a Translations screen for
  changing texts.

The admin runs under Preflow's strict, nonce-based CSP: no inline handlers or styles.
Hand-written `<script>`/`<style>` tags in your own templates need
`nonce="{{ csp_nonce() }}"`.
