# Members Groundwork A: follow-ups for plan B

These were found while building plan A (`2026-10-22-members-groundwork-a.md`) and its
reviews. Plan B has to handle them before smart-stack.works adopts the groundwork.

## Must be handled in plan B

- **Member roles on SQL:** `DynamicRecord::get()` returns JSON strings for array fields
  on SQL storage. Member `roles` must be decoded before `getRoles()` uses them.
- **Throttle storage:** the storage is hard-wired to `'default'`. smart-stack.works has
  the default `json` with SQLite named `sqlite`, so throttle rows would land in JSON
  files. Add a config option (e.g. `config/data.php` `'throttle' => ['storage' => …]`).
- **APP_KEY:** `Throttle::client()` refuses an empty or default `app.key`. Check that
  smart-stack.works sets `APP_KEY` on the server and locally.
- **Deploy order:** run `preflow migrate`, `folio:extensions:apply` and
  `folio:extensions:check` before the release symlink switches. Broken extension classes
  and a broken `extensions.json` throw in production too.
- **Boot side effects:** a provider that queries tables before `migrate` has run could
  block `migrate` on an empty database. Check this when the site gets SQLite.
- **Missing state table:** if the default storage becomes SQL, the extension state
  table gives a raw "missing table" error until `apply` has run.
- **Package layout:** packaged extensions (rbac, codes) keep `Extension.php` in `src/`.
  The strict scan of the extension folder would otherwise sweep in `tests/`.
- **Record actions on publishing types:** they get the working copy and bypass the
  Publisher's versioning. Members are not a publishing type, but keep that in mind.
- **User types with file fields:** media and uploads are exported wholesale. Before a
  user type gets an asset or upload field, its files must stay out of exports.
- **Type keys shadowed by Folio routes:** reject type keys that Folio's own routes
  shadow (`extensions`, `assets`, `login`, …) at boot.
- **Action buttons:** show a record action button only when the user also has
  `{type}.edit`. The route already enforces it.

## Recorded decisions

- **`preflow migrate` lives in `preflow/devtools`:** it reaches production through
  Folio's dependency on devtools. A site without Folio that deploys with `--no-dev`
  has no migrate.
- **Folio table sync is SQLite-only:** it creates and adds, and never drops or
  renames.

## Minor, deferred

- **Settings:** a cleared number setting reads `''`.
- **migrate:** a MySQL-only site run without `--storage` still defaults to `sqlite`.
- **Sorting:** `list.sort` on number fields sorts as text on SQLite.
- **Tests:** 129 PHPUnit doc-comment deprecations in FolioAuthTest.

## Requested by the user (2026-10-22)

- Folio sidebar structure: Pages belong in their own group, apart from the data and
  collection types (Forces, Practices, …). The Site singleton belongs under Settings.
  Let model JSON choose a type's sidebar section (and order). Today every file type
  lands in Content.

## From plan B (2026-10-22), for step 2 and later

- Throttle increments are read-modify-write; make them atomic before the member login uses the throttle.
- Member email format and uniqueness validation (registration).
- `decided_at` and `registered_at` are read-only in the admin; the approval/registration flow must set them.
- An explicit `list.columns` can still name a hidden field — make that an error.
- Read-only selects/dates show the raw stored value, not the option label/format.
- Readable codes: also strip U+2212 (minus) and U+00AD (soft hyphen).
- `check()` on codes is unthrottled — visitor-facing code must use `redeem()` with a client.
- A delegation rule is needed before any site manages staff accounts as a Folio user type (`rbac_roles` on staff is refused for now).
