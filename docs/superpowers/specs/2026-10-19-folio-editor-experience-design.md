# Folio Editor Experience — Design

**Date:** 2026-10-19
**Packages:** `preflow/folio` (main), `preflow/devtools` (CLI), `preflow/view` (image variants)
**Status:** Approved (2026-10-19).
**Depends on:** groundwork, content i18n, blocks and singletons specs.
**Context:** With blocks, Folio can model a Storyblok-style site. This spec covers what makes
Storyblok pleasant to *edit* in, adapted to Preflow:
- a visual editor where you click the page to edit it;
- drafts that don't touch the live site until published;
- a real asset library;
- for SSH users, pulling live content to a local copy.

It is split into four parts that are planned and merged separately, in this order.

| Part | Scope |
|---|---|
| 4a | Visual editor: split view, click-to-edit, two-way highlight, drag reorder |
| 4b | Draft / publish, autosave, revisions, scheduled publish, share-preview links |
| 4c | Asset library with metadata, focal point, image variants, picker |
| 4d | `folio:pull`: live server → local over SSH |

## Principles applied

- **Server-rendered.** The admin stays Twig plus htmx fragments. JS islands are small
  vanilla modules in `admin.js` with no build step (drag and drop, preview bridge). There
  is no SPA and no client-side state store.
- **The preview is the real site.** The preview renders the app's own templates and
  components with draft data. There is no separate "preview API", and editor markers exist
  only in preview renders.
- **Zero external requests and strict CSP**, in the admin as well.
- **Hosting reality:** no workers, so scheduled work runs through a cron-invoked command.

## Non-goals

- Inline (contenteditable) text editing inside the preview. Edits happen in the form; the
  preview follows instantly.
- Real-time multi-user collaboration and presence. Conflicts are detected (4b), not merged.
- Workflow stages (review/approval), comments, tasks.
- `folio:push` (local → live). Live is the source of truth for content; code goes through
  git. See 4d.
- Image editing (crop/rotate) beyond the focal point.

---

## 4a. Visual editor

**Layout.**
- Types with a `frontend` route open in a **split view**: the form on the left, the live
  preview on the right.
- The pane widths are resizable, and the choice is remembered in `localStorage` (an admin
  convenience, not a cookie).
- The existing Desktop/Tablet/Mobile widths stay.
- "Form only" and "Preview only" toggles are available.
- Types without a route keep the plain form.

**Click-to-edit.**
- Preview renders already mark blocks with `data-folio-block="{_uid}"` (blocks spec).
- A preview-only stylesheet and script (injected by `PreviewController`, nonced, never on
  the public site) outline a block on hover and show its label.
- **Clicking a block** in the preview scrolls the form to that block's card, expands it,
  collapses siblings if "focus mode" is on, and focuses its first field.
- **Focusing a field or card** in the form highlights the matching block in the preview and
  scrolls it into view.
- Communication is direct DOM access: the preview is same-origin, whichever preview mechanism
  the groundwork chose. It falls back to `postMessage` if that mechanism makes it
  cross-document.

**Field-level targets (optional).**
- `{{ folio_editable('headline') }}` inside a block template emits `data-folio-field="…"`
  for that field in preview renders only, and nothing on the public site.
- Clicking such an element focuses that exact field.
- Without it, the click is block-level, as in Storyblok's default `storyblokEditable`.

**Preview updates.**
- The existing region patching extends from top-level fields to blocks: the changed blocks
  are patched by `_uid`, with a full reload as fallback.
- Scroll position is preserved across full reloads.

**Drag reorder.**
- Block cards can be dragged (native HTML5 drag and drop) within a `blocks` field.
- Up/down buttons stay, for keyboard use and accessibility. The keyboard pattern is: focus
  the handle, Space to lift, arrows to move, Space to drop, with an `aria-live`
  announcement.
- Because input names are `_uid`-keyed, reordering only rewrites the hidden `_order`
  inputs.
- Dragging across fields or levels is a non-goal.

**Navigation guard.** Leaving the form with unsaved changes asks for confirmation, unless
autosave (4b) is on.

## 4b. Draft and publish

**Model.**
- Each record has a **working copy**, which is what the admin edits, and optionally a
  **published snapshot**, which is what the public site reads.
- It is implemented storage-agnostically: the snapshot lives in a shadow type with the same
  fields and the table `{table}__published`, written through `DataManager`. JSON, SQLite and
  MySQL all work unchanged.
- Opt-in per type with `"publishing": true`. It is the default for types with a `frontend`
  block and for singletons.
- When on, it replaces the groundwork's `frontend.published` field convention. When off,
  that convention still applies.

**States.** Shown in the list and editor header, and derived rather than stored:
- **Draft:** no snapshot exists.
- **Published:** the snapshot equals the working copy.
- **Changed:** the working copy differs from the snapshot.

**Actions.**
- **Save** writes the working copy.
- **Publish** saves and copies it to the snapshot, stamping `_published_at`/`_published_by`.
- **Unpublish** removes the snapshot, so the public URL returns 404.
- **Discard changes** resets the working copy from the snapshot.
- Publishing validates fully. Saving a draft validates only types and shapes, so
  half-finished work can be saved.

**Frontend.**
- `FrontendResolver` and `folio_get()` read snapshots.
- The preview reads the working copy.
- Per-locale availability (`_locales`, i18n spec) is taken from the snapshot.

**Autosave.** For publishing types:
- the working copy autosaves a few seconds after the last change (htmx, debounced);
- a header indicator shows "Saved · not published".
- Because publishing is a separate step, autosaving is safe.

**Conflicts.**
- Records carry a `_version` counter, and saves send the version they were loaded with.
- On a mismatch the save is refused with "Someone else changed this record", and the editor
  offers to reload or to overwrite.

**Revisions.**
- Every publish appends a revision (a full copy) to `{table}__revisions`, keeping the last
  20 per record (configurable).
- The editor lists them with date, user and a list of changed fields, and can **restore**
  one into the working copy. The restored copy must then be published again.
- There is no visual diff in this pass.

**Scheduled publish.**
- An optional "Publish at" date-time on the working copy.
- `php preflow folio:publish-scheduled`, run from cron (e.g. every 5 minutes), publishes
  everything that is due. It is idempotent and holds a lock against overlapping runs.

**Share preview link.**
- A "Copy preview link" action creates a signed URL (HMAC with the app key; it expires after
  7 days by default) that renders the working copy on the real frontend route for someone
  who is not logged in.
- The response sends `X-Robots-Tag: noindex` and `Cache-Control: private, no-store`.
- Links can be revoked by rotating a per-record token.

## 4c. Asset library

**Model.**
- A system type `_asset` holds:
  - file path, original name, MIME type, byte size;
  - width/height (images), duration (video, when cheap to read);
  - `alt` (translatable), `title`, `focal_point` (x/y percentages);
  - `poster` (an image asset, for videos);
  - created by and created at.
- Files live under a public, web-server-served directory (`public/media/Y/m/…`,
  configurable), so no PHP is involved in delivery. The existing `UploadController` path
  stays only for legacy values.

**Library screen** (`{prefix}/assets`):
- grid and list views;
- search by name, title or alt;
- filters by kind (image, video, document);
- multi-file upload (plain multipart; htmx progress is an enhancement);
- metadata editing in a side panel, with a focal-point picker (click on the image);
- **usage**: "used in 3 records", with links, computed by scanning asset references.
  Deleting a used asset is refused with that list.

**Image variants.**
- On upload, with GD or Imagick available, generate a WebP version and the configured width
  presets, plus AVIF when supported. Variants are written next to the original, eagerly, so
  there is no runtime image server.
- Variants are regenerated when presets change via `php preflow folio:assets:variants`.
- `{{ folio_image(asset, 'preset', {sizes: …}) }}` emits `<picture>`/`<img>` with `srcset`,
  explicit `width`/`height`, `alt` from the asset in the current locale, and
  `object-position` from the focal point (via a class, not an inline style, because of CSP).
  It reuses `preflow/view`'s `ResponsiveImage` and preset config where possible.

**Asset field changes.**
- `asset` fields store asset **ids**.
- The editor shows thumbnails plus "Choose" (opens the library as a picker in the existing
  drawer, loaded with htmx) and "Upload" (uploads into the library and selects the file).
- Legacy path values keep rendering. `php preflow folio:assets:import` moves them into the
  library and rewrites the references.

## 4d. `folio:pull` — live content to local

**Config** (`config/folio.php`):

```php
'remotes' => [
    'production' => ['ssh' => 'user@host', 'port' => 22, 'path' => '/home/user/www/smeyer.me'],
],
```

There are no credentials in config. The command uses the user's SSH agent and
`~/.ssh/config`. Host keys follow normal SSH rules: never disabled, so a changed host key
aborts the pull.

**What content means.** It is everything Folio owns at runtime, and nothing else:
- the JSON storage directories of Folio types (including `__published` and `__revisions`);
- SQLite files used by Folio types;
- the media directory;
- **not** code, `.env`, cache, logs or sessions.

**Mechanism.**
1. Locally: `php preflow folio:pull production [--dry-run] [--only=data|media]`.
2. Over SSH it runs `php preflow folio:export --stdout` **on the remote**. That writes a
   consistent tar stream: SQLite is copied via `VACUUM INTO` and JSON is read under a
   short lock. This needs PHP CLI on the server, which shared hosts with SSH (e.g. cyon)
   provide.
3. Before touching anything locally, the command writes a backup to
   `storage/backups/pull-YYYYmmdd-HHMMSS.tar.gz`.
4. It unpacks into place, prints a summary (records and files added, changed, removed), and
   suggests `cache:clear`.

`--dry-run` lists the differences without writing.

**Also useful on its own:** `folio:export` and `folio:import <file>` work without SSH as a
content backup and restore.

**Why there is no push.** Live edits would be silently overwritten. The initial content of a
new site comes from a seeder run on deploy (see the smeyer.me spec). If a push is ever
needed, it is a separate design with a mandatory remote backup.

---

## New / affected files (overview)

- **4a:**
  - `admin.js` (preview bridge, drag and drop)
  - `preview-editor.css`/`.js` (preview-only, injected)
  - the `folio_editable` helper
  - split-view `form.twig`
- **4b:**
  - `Publishing/` (snapshot service, states, revisions, scheduler)
  - `PublishScheduledCommand`
  - signed preview links (controller, middleware)
  - `_version` handling in `DraftRecordFactory`/`AdminController`
  - list/editor state badges
- **4c:**
  - `Assets/` (the `_asset` type, library controller, variant generator, usage scanner)
  - `AssetFieldType` (ids, picker)
  - the `folio_image` helper
  - `AssetsImportCommand`, `AssetsVariantsCommand`
- **4d:**
  - `FolioExportCommand`, `FolioImportCommand`, `FolioPullCommand` (devtools command
    interface, registered by Folio)

## Testing (per part)

- **4a:**
  - the preview-only markers and assets are absent from public renders;
  - the click → card mapping (DOM test with a fixture);
  - drag and keyboard reorder rewrite `_order`;
  - CSP guard on the new templates.
- **4b:**
  - state derivation;
  - publish, unpublish and discard;
  - the public site reads the snapshot while the preview reads the working copy;
  - draft save with failing required rules succeeds, while publish fails;
  - a version conflict is refused;
  - revision cap and restore;
  - scheduler publishes due items once (concurrent run is locked out);
  - signed link: valid, expired, revoked, noindex headers.
- **4c:**
  - upload creates an asset with dimensions;
  - variants are generated when GD is present and skipped cleanly when absent;
  - `folio_image` output (srcset, width/height, alt per locale, focal class);
  - usage scan blocks delete;
  - legacy import rewrites references.
- **4d:**
  - export stream contents and exclusions;
  - SQLite snapshot consistency;
  - import restores a backup;
  - pull against a fake "remote": a local command runner replaces SSH in tests, so there is
    no network;
  - the pre-pull backup is written;
  - `--dry-run` writes nothing;
  - a non-zero SSH exit aborts before any local change.

## Open for the builder

- Exact split-view styling within the existing admin look.
- Whether revisions store full copies or diffs (full copies suggested at this scale).
- Which video metadata is cheap enough to read without ffprobe (suggested: skip duration if
  unavailable).
