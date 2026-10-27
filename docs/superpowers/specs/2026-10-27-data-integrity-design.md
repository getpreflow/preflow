# Data Integrity: Clearing, Unique Fields, Typed Columns — Design

**Date:** 2026-10-27
**Status:** Draft for the owner's review (builder check done).
**Context:** the rest of step 3 of the BG Genius framework order in `ROADMAP.md` ("Data integrity for the flow
editor and games"; the compare-and-set draft save is done in 0.41). Also the roadmap's "Security and data
integrity" entries *Saving null clears SQL columns* and *Unique fields and typed columns*. Current version:
v0.41.2.

## Why

Edge cases found while testing (by the sessions, not by a site's visitors):

- **Clearing does not clear on MySQL.** `PdoDriver::save()` drops null values before the write. On MySQL the
  upsert (`ON DUPLICATE KEY UPDATE`) then keeps the old value; on SQLite (`INSERT OR REPLACE`) the whole row
  is rewritten and the field is cleared. The same save does two different things. Sites clear with `''`.
- **Nothing stops duplicates.** There is no unique rule (the validation design sketched `Unique`, never
  built). Two games can share a slug and the public page shows whichever the database returns first. Codes
  check for a duplicate before writing, which two simultaneous saves can both pass. MySQL's value columns are
  case- and accent-insensitive, so `cafe` and `café` collide there and not on SQLite.
- **Every column is text.** Folio's sync creates every field as `LONGTEXT` (MySQL) or `TEXT` (SQLite).
  Numbers sort as text (10 before 9) unless a query casts, text columns can only be indexed with a prefix
  (codes' `forRecipient()` scans), and no proper unique index is possible.

The owner (2026-10-27): typed columns "make no sense at all" otherwise; this is plainly wrong and is fixed
for every site, not only new ones.

## Owner's decisions

1. **Existing tables are converted too**, on every site, not only new tables (BG Genius).
2. **A backup is taken first**, every time a conversion runs.
3. **Safest approach: break, fix, retry.** If any stored value does not fit its new type, nothing is
   changed: the conversion stops and lists every value that doesn't fit (table, record id, field, value).
   The owner fixes them and runs it again.

## Goals, non-goals, anti-goals

**Goals:**
- Saving a null (or a cleared field) clears the column on SQLite, MySQL and JSON alike.
- A field can be declared unique, optionally within a scope (other fields); a duplicate is refused by
  validation (a readable "already taken" in Folio's form) **and** by the database, so two simultaneous saves
  cannot both win.
- A save never overwrites or deletes a different record because a unique value matched.
- Record tables get real column types derived from the field types; existing tables are converted safely.
- Field types (built-in and extensions) say which column kind they store.

**Non-goals:**
- Changing how values look to templates and PHP (a number still comes back as Folio's field type returns it).
- Typed columns for the JSON driver (it has no columns; it gets the clearing and unique behaviour).
- A general schema-diff / ALTER tool beyond what record tables need.
- Changing the collation of value columns (see Unique fields: the unique index decides what counts as equal).

**Anti-goals:**
- A conversion that changes some tables and fails on others, or changes a value's meaning silently
  (`'12 Spieler'` becoming `12`, `'ja'` becoming `0`).
- A deploy that leaves the live release unable to write (the old release still serving while the schema
  changed under it).
- A site's data lost without a restorable backup.
- holz-objekte.org, smart-stack.works or smeyer.me breaking on upgrade.

## Design

### 1. Clearing

- `PdoDriver::save()` writes null values as `NULL` (insert and update); it no longer filters them out.
- The SQL upsert targets the **primary key only** and never replaces a row: SQLite uses
  `INSERT … ON CONFLICT(id) DO UPDATE SET …` instead of `INSERT OR REPLACE` (which deletes the old row, and
  would delete a *different* row when a unique index matches). MySQL keeps `ON DUPLICATE KEY UPDATE`, which is
  safe only while the primary key is the only unique key; with unique indexes (section 2) a duplicate must
  fail, not update the other row, so MySQL writes as an `INSERT` for a new id and an `UPDATE … WHERE id = ?`
  for an existing one (or an equivalent that cannot hit another key; left to the builder, with a test).
- Columns the caller did not pass stay as they are (a partial save stays partial on both databases).
- The JSON driver: a null value is stored as null (or the key removed) so it reads back as empty.
- `''` and null: a field type's empty value is what it stores. In typed columns (section 3) an empty number,
  date or yes/no is stored as NULL; empty text stays `''`.

### 2. Unique fields

- A type definition marks a field unique: `"unique": true`, or `"unique": ["purpose"]` for a scope (the
  value is unique among records with the same values in the scope fields). Available to Folio types,
  package types (codes: `code` unique within `purpose`) and app models.
- **Validation:** saving through `DataManager` (and Folio's form) checks the value against other records
  (excluding the record itself) and fails with a field error, message key in the validation translations
  ("already taken" / "bereits vergeben" / "déjà utilisé"). A `Unique` validation rule is the public form of
  this check, for app code that validates input before saving.
- **Database:** on SQL drivers, sync creates a unique index on the field (plus its scope). The index is what
  makes two simultaneous saves safe: the second write fails, and the driver turns that failure into the same
  field error (not a 500). On JSON, the check runs under the driver's write lock.
- **Equality:** on MySQL, unique (and indexed) columns compare exactly (`utf8mb4_bin`, as ids and keys
  already do), so `cafe`/`café`, `Cafe`/`cafe` are different values on every database. Fields that want
  case-insensitive uniqueness normalise the value (slugs are lower case already) (my call).
- **Empty values** are not unique-checked: many records may have no slug (NULL in the index).
- **Translatable fields** (stored as a JSON map of locales) can be unique per locale: validation checks each
  locale's value. The database cannot index inside the JSON map, so for these the check runs inside the
  save's transaction under a lock that makes simultaneous saves of the same type wait (left to the builder,
  with the requirement: two simultaneous saves with the same value in a locale cannot both succeed).
- **Folio publishing:** the unique index sits on the working table only; `__published` and `__revisions`
  hold copies and are not unique. A draft whose slug another record already uses fails on save, as any save.
- **Existing duplicates:** creating a unique index on a column that already has duplicates is a conversion
  failure like a value that doesn't fit (section 4): listed, nothing changed.

### 3. Typed columns

- Each field type declares a **column kind** (a new method on Folio's field type contract, with a default;
  `FieldKinds` in data learns the mapping for types defined without Folio). Built-in mapping (my call; the
  builder may refine the exact SQL types):

  | Field type | Column (MySQL / SQLite) | Stored value |
  |---|---|---|
  | number | `DECIMAL(…)` or `DOUBLE`, `BIGINT` for integer-only config / `NUMERIC`/`INTEGER` | number, NULL when empty |
  | boolean | `TINYINT(1)` / `INTEGER` | 0 or 1, NULL when never set |
  | date | `VARCHAR(32)` / `TEXT`, ISO 8601 as today (`Y-m-d`, `Y-m-d\TH:i`, with offset) | sorts as text correctly because the format is fixed; NULL when empty |
  | string, select, relation (single id), env, purpose and other short values | `LONGTEXT` / `TEXT` as today, **unless** the field is unique or indexed: then `VARCHAR(n)` (`n` from the field's `length`, default 191) / `TEXT`, and validation limits the value to `n` characters | text |
  | text, rich text, blocks, matrix, asset lists, translatable fields, JSON | `LONGTEXT` / `TEXT` (unchanged) | |

- Strings get a fixed length only when they are unique or indexed (my call): a length that depends on the data
  present at conversion time would differ between sites, and strings without a limit (codes' `payload` holds
  JSON) must not start failing on long values.
- Dates stay ISO text, not `DATETIME`: the stored values carry offsets and two formats, and a text column of a
  fixed format sorts and compares correctly (my call).
- New tables are created typed. Columns added later are added typed, and their unique indexes with them.
  `preflow migrate`'s sync **never** changes an existing column or adds a unique index to one (it stays
  additive while the previous release serves); that is the conversion's job (my call).
- **Drift:** a column's kind can change after conversion (a field turns translatable when a site adds a
  locale, a relation becomes `multiple`, a field's type is edited, a field becomes unique). Sync detects a
  column whose kind differs from the definition and prints a warning; a site not converted yet sees one per
  column. A column whose kind changed later is fixed with a migration in that project (owner, 2026-10-27).
- **Which tables:** every record table the data layer creates from a type definition (`createRecordTable`:
  Folio types, codes, members, media assets, user preferences, stored overrides) and its `__published`
  table, which the frontend reads and sorts. `__revisions` stays text: it is history, and an old revision's
  value must never block a conversion (my call). App models with hand-written migrations keep their own
  schema; they get unique fields through `#[Field(unique: true)]` / `unique: ['scope']` (validation and the
  database error turned into a field error) and declare the index in their own migration (my call).
- Numbers read back as before through the field type (`NumberFieldType::fromStorage`), so templates do not
  change. Queries that cast (`CAST(… AS DECIMAL)`, codes' numeric cast) keep working.

### 4. Converting existing tables (a one-time script, not a framework command)

Owner, 2026-10-27: the framework has one user, so the conversion is not a framework feature; the owner's sites
are converted with a one-time script. The framework keeps clearing, unique fields, typed new tables and columns,
and the drift warning in `preflow migrate` (which also names a site that is not converted yet). A field whose
kind changes later is fixed with a migration in that project.

- **The script:** `tools/convert-typed-columns.php` in the monorepo root (not in any package, not split, not
  on Packagist). It boots a site's app from the site's directory, takes the record tables from `RecordTables`,
  and runs: check → backup → convert → re-check. `--check` reports only.
- **When:** after the site's deploy of the new release (the new release works on text and on typed columns;
  migrate stays additive while the previous release serves). First against a copy of the site's database
  locally, then on the live site with the owner's go-ahead (uploaded for the run and removed after).
- **Order of work:**
  1. Check every value of every typed record table against its target kind, and every unique field for
     existing duplicates. Any failure: print every one (table, record id, field, value, why) and stop.
     Nothing was changed.
  2. Back up the database (MySQL: `mysqldump`, credentials in a temporary 0600 option file; SQLite:
     `VACUUM INTO`) into the site's `storage/backups/` with a timestamp. If the backup fails, stop.
  3. Convert table by table. Empty strings in number, date and yes/no columns become NULL (the one conversion
     that is not a failure). MySQL: one `ALTER TABLE` per table (types and unique indexes together); SQLite:
     rebuild the table (create typed, copy, swap) in a transaction.
  4. Re-check: every converted value reads back equal to the original (numbers numerically, text exactly);
     a mismatch stops with the backup path.
- **What doesn't fit:** a number field holding text (`'12 Spieler'`), a yes/no holding anything but `''`,
  `0`, `1`, `true`, `false`, a date not in the stored formats, a short-text value over the limit on a unique
  field, duplicates on a unique field.
- Already typed columns are skipped, so a rerun after an interruption continues (with a new backup; the
  earlier ones stay, nothing rotates them).
- holz-objekte.org converts in its own session with the same script.

## Constraints

- PHP 8.4. Breaking changes allowed in 0.x; every consumer migrated in the same release.
- SQLite (smart-stack.works' members, codes, throttle; local development) and MySQL/MariaDB 10.6+ (production
  sites) both supported; the MySQL test group (`composer test:mysql`) covers the MySQL paths.
- Live data: the conversion is tested against copies of the real sites' databases before it runs live.
- Live server limits: long SSH jobs are killed after about 12 minutes; the conversion must finish well within
  that for the sites' sizes, or work per table so a rerun continues.
- smart-stack.works' deploy order (vendors → migrate → publish) stays; the conversion is a separate step.

## Verification

- Clearing: a null save clears a field on SQLite, MySQL and JSON; a partial save leaves other columns alone.
- Unique: a duplicate is refused with a field error through `DataManager` and Folio's form; two simultaneous
  saves with the same value: one wins, the other gets the field error (SQLite and MySQL); a save never changes
  another record; scoped uniqueness (codes per purpose); translatable per locale.
- Conversion script: `--check` lists bad values and duplicates and changes nothing; a clean run backs up,
  converts, re-checks; a second run does nothing; numbers sort numerically after it.
- Copies of smart-stack.works and smeyer.me databases convert cleanly (or list what to fix); both sites'
  suites stay green.

## Left to the builder

- The exact SQL types per kind and the exact MySQL write that cannot hit another unique key.
- Where the column-kind mapping lives for types without Folio, and the field type method name.
- The lock for translatable unique fields.
- How the backup is restored (documented command or steps).

## Size

Large: one plan of about 6–7 tasks (clearing + safe upsert; unique definition, validation and indexes; column
kinds and typed sync; the one-time conversion script with check, backup and re-check; Folio form errors and
translations; docs, upgrade notes, release). One release.
