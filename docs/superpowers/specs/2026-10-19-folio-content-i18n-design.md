# Folio Content i18n — Design

**Date:** 2026-10-19
**Packages:** `preflow/i18n`, `preflow/data`, `preflow/folio`
**Status:** Approved (2026-10-19).
**Depends on:** groundwork spec (`2026-10-19-groundwork-csp-htmx-folio-basics-design.md`),
especially §5 generic frontend rendering.
**Context:** `preflow/i18n` translates *interface strings* (`lang/{locale}/*.php`, `t()`) and
detects the locale. Nothing translates *content*. Multilingual sites are common; smeyer.me
launches in English with German undecided, so content i18n is built now, before blocks, so
that blocks are translation-aware from day one.

## Goal

One record, one structure, translated values. An editor switches the editing language, sees
which fields are translated and which are shared, and sees the default-language text where
a translation is missing. The public site serves `/` in the default language and `/de/…`
for German, with translated slugs, correct `<html lang>`, `hreflang` alternates and a
language switcher. No cookie is needed for any of it.

## Storyblok, adapted

Storyblok offers *field-level* translation (one story, translatable fields) and
*folder-level* translation (a separate tree per language). We adopt **field-level only**:
- it keeps one structure per page;
- it keeps relations and ids shared;
- it is enough for a personal or company site.

The folder-level model is an anti-goal: duplicated trees drift apart.

Kept from Storyblok:
- the language switcher in the editor;
- the default-language value shown as a reference/placeholder;
- "translatable" as a per-field flag in the schema;
- per-language preview.

Not kept:
- machine translation;
- translation-agency workflows and XLIFF export (possible later add-on).

## Non-goals

- Translating the Folio admin interface itself (separate, uses `t()`; later).
- Machine translation, XLIFF/CSV import-export, translation memory.
- Per-locale draft/publish states (the editor-experience spec builds draft/publish; it must
  respect the per-locale availability defined here).
- Locale-specific *structure* (different blocks per language). Structure is shared by design.

## 1. Fixes in `preflow/i18n`

1. **No cookie by default with the prefix strategy.** `LocaleMiddleware` sets
   `Set-Cookie: locale=…` on every response today. That breaks "no cookies" sites, and with
   `url_strategy: prefix` the URL is already the source of truth.
   - New config key `'cookie' => bool`, defaulting to `false` for `prefix` and `true` for
     `none`.
   - When enabled, only send the cookie if it changed.
2. **Escape parameters in `t()`.** The function is registered `isSafe: true` and substitutes
   `:params` unescaped, so user data passed as a parameter is an XSS hole. Parameters get
   escaped; the translation string itself stays trusted.
3. **Boot without `lang/`.** `bootI18n` returns early when there is no `lang/` directory. A
   content-only multilingual app may not have interface strings yet, so `config/i18n.php`
   alone must be enough.
4. **Template helpers.**
   - A `locale` template global.
   - `locale_url(path, locale)`, which builds a prefixed path: no prefix for the default
     locale.
   - `available_locales()`.

## 2. Schema: translatable fields

```json
"fields": {
    "title": { "type": "string", "translatable": true, "validate": ["required"] },
    "slug":  { "type": "string", "translatable": true, "validate": ["required"] },
    "cover": { "type": "asset" },
    "order": { "type": "number" }
}
```

- `translatable` defaults to `false`. Translatable means the value differs per language.
  Everything else is shared across languages. Suitable defaults per field type (e.g.
  `asset` and `relation` are rarely translatable) are documented, not enforced.
- Content locales come from `config/i18n.php` (`available`, `default`, `fallback`). With one
  locale configured, `translatable` has **no effect**: values are stored as plain scalars,
  exactly as today, so single-language apps see zero change.

## 3. Storage (`preflow/data`)

- A translatable field's stored value is an object keyed by locale:
  `"title": {"en": "About", "de": "Über mich"}`.
- **Read tolerance both ways**, so a field can change its flag without a migration:
  - a plain scalar on a translatable field is read as the default-locale value;
  - an object on a non-translatable field is read as its default-locale value.
  - The next save writes the new shape.
- `DynamicRecord::get($field)` keeps returning the raw stored value (no behaviour change for
  existing callers).
- A new `LocalizedRecord` view: `$record->localized('de')`. It returns field values
  resolved for one locale, following the fallback chain `de → fallback → default`. It also
  reports per field whether the value is its own or a fallback (`isFallback($field)`); the
  admin needs this.
- **Locale-aware queries:**
  - `QueryBuilder::locale('de')->where('slug', 'ueber-mich')` matches against the locale's
    value of translatable fields;
  - without `locale()`, a translatable field matches against the default locale;
  - `JsonFileDriver` implements this in PHP;
  - `SqliteDriver`/`MysqlDriver` use `json_extract`/`JSON_EXTRACT` on the stored JSON string.
- **Search** on a searchable translatable field searches the query's locale.
- **Validation:** `required` on a translatable field applies to the **default locale**
  only. Other locales may be empty (they fall back). Other rules apply to every locale that
  has a value.

## 4. Availability per locale

Fallback is right for field values but wrong for whole pages: serving English text at
`/de/ueber` duplicates content. So availability is explicit, which keeps it intentional:

- A system field `_locales` (list of locale codes) on every record of a type that has
  translatable fields. New records get `[default]`.
- The editor sets it with one checkbox per language next to the language switcher: "Visible
  in: ☑ en ☐ de". The default locale cannot be unchecked.
- The frontend resolves a record in locale X only if X is in `_locales`, and returns 404
  otherwise. Within an available locale, missing translatable values fall back field by
  field.

## 5. Frontend (`preflow/folio`)

- The route patterns from the groundwork spec are matched after `LocaleMiddleware` has
  stripped the prefix. `FrontendResolver` runs its lookup with `->locale($request locale)`,
  so translated slugs resolve: `/de/ueber-mich`.
- Templates receive `record` already localized, `rendered` in that locale, plus:
  - `locale`;
  - `alternates`: a list of `{locale, url}` for every available locale of this record.
- Helpers:
  - `folio_url(record, locale = current)`;
  - `{{ folio_alternate_links(record) }}`, which emits `<link rel="alternate"
    hreflang="…">` including `x-default`.
- An app builds its language switcher from `alternates`.
- `RecordRenderer` and relation/matrix rendering resolve referenced records in the same
  locale.

## 6. Admin (`preflow/folio`)

- A **language switcher** in the edit-form header: `?locale=de` on edit URLs, with the
  availability checkboxes (§4) beside it.
- Editing locale X:
  - translatable fields show and submit X's value;
  - shared fields are visible and editable but marked with a small "shared across languages"
    label, because changing one changes every language;
  - an empty translatable field shows the fallback value as a placeholder plus a "falls back
    to English" hint;
  - an optional toggle shows the default-language value read-only under each translatable
    field, as a translation reference.
- **Save merges.** The form posts only locale X's translatable values, and the controller
  merges them into the stored map, so other locales are never overwritten. This happens in
  one shared draft-building function, used by both save and live preview. That is the
  `DraftRecordFactory` the live-preview spec deferred; build it here.
- **Live preview** renders in the editing locale.
- The **list view** shows one small badge per available locale, and marks a locale where
  required-in-default fields lack a translation (e.g. "de · 3 missing").
- **Field types stay unaware of locales.** Translation is handled around them: input naming,
  merge, fallback. A field type's `renderEditor`/`normalizeInput` sees one locale's value,
  exactly as today.

## 7. Ready for blocks

The blocks spec (next) stores nested block data inside a record. The rule it must follow:
**translatable leaf values inside blocks use the same locale-map shape**, while block
structure (which blocks, their order, their types) is shared. `LocalizedRecord` resolves
nested block data recursively. This spec ships the recursive resolver with tests on nested
arrays, so blocks only have to declare which sub-fields are translatable.

## New / affected files

- i18n:
  - `LocaleMiddleware` (cookie option)
  - `TranslationExtensionProvider` (escaping, helpers)
  - core `Application::bootI18n` (boot without `lang/`, cookie config)
- data:
  - `LocalizedRecord.php`
  - `QueryBuilder::locale()`
  - locale-aware where/search in `JsonFileDriver`, `SqliteDriver`/`PdoDriver`, `MysqlDriver`
  - `TypeFieldDefinition` `translatable`
  - validation rule scoping
- folio:
  - `DraftRecordFactory` (shared by `AdminController` and `PreviewController`)
  - locale switcher and availability in `form.twig`
  - list badges
  - `FrontendResolver` and `FrontendController` (locale)
  - Twig helpers (`folio_url`, `folio_alternate_links`)
  - `RecordRenderer` locale pass-through
- `examples/folio-demo`: two locales, a translated page.

## Testing

- **i18n:**
  - no `Set-Cookie` with the prefix strategy;
  - the cookie is set when enabled and only on change;
  - `t()` escapes a `<script>` parameter;
  - boot with `config/i18n.php` and no `lang/`;
  - `locale_url` for default and non-default locales.
- **Data:**
  - read tolerance (scalar ↔ map) both directions;
  - the fallback chain;
  - `isFallback`;
  - locale-aware `where` on all three drivers (SQLite in memory, MySQL behind the existing
    test toggle if there is one);
  - `required` applies to the default locale only;
  - recursive resolution of nested arrays.
- **Folio:**
  - saving locale `de` leaves `en` untouched;
  - preview renders the editing locale;
  - the placeholder shows the fallback value;
  - shared fields carry the marker;
  - `/de/ueber-mich` resolves;
  - `/de/…` returns 404 when `de` ∉ `_locales`;
  - alternates and hreflang output;
  - a single-locale app stores plain scalars (regression).
- The existing suites stay green.

## Open for the builder

- Exact markup and placement of the language switcher, within the existing admin styling.
- Whether `_locales` is shown for types without translatable fields (suggested: no).
