# Folio Interface Translations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Folio's whole admin, and the admin screens of every package, follow each user's chosen language. English, German and French are delivered. Packages bring their texts through a discovered registry, and sites and admins can override them. Released as v0.37.0.

**Architecture:**
- **Discovery:** `preflow/i18n` discovers each installed package's language files from `extra.preflow.translations` (Composer's `installed.json`, as the commands registry does). Each group is marked `admin` or `public`.
- **Lookup chain:** the `Translator` resolves through admin overrides (admin groups on admin requests only), then the site's `lang/`, then the package's files. It falls back from the user's locale to the site default, then English.
- **Folio:** it sets the locale per admin request from the user's stored preference, moves every admin string into language files, translates site model labels, and adds a Translations screen for admin overrides.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig (`t()`), ext-intl when present.

**Spec:** `docs/superpowers/specs/2026-10-26-folio-interface-translations-design.md`. Every section binds, including the owner's answers under "Decisions".

## Global Constraints

- **Owner's rule:** the translation capability (discovery, layers, fallback, overrides interface, label maps, the check command) lives in `preflow/i18n` (and `preflow/data` for model files). Folio only uses it and adds UI.
- **Delivered languages:** `en`, `de`, `fr`. Every key exists in all three in the same commit that introduces it. German uses "du" and standard spelling ("Grüße", ß). French uses neutral wording where possible, and "vous" where a sentence needs a pronoun. Two-letter locale codes only; no right-to-left languages.
- **Scopes:** `admin` groups are the admin interface and staff mails. `public` groups are member pages, mails to members, and validation used on public forms. Overrides apply to `admin` groups on admin requests only. **Public pages render byte-identically** to v0.36.0, and members' German public texts keep "Sie".
- **Precedence:** admin override > site `lang/{locale}/{group}.php` (key by key) > package file. Locale fallback: the user's locale, then the site default, then `en`. A key found nowhere renders as the key (caught by `translations:check`).
- **Overrides:** plain text only. A text containing `<` or `>` is refused. Keys whose delivered English text contains markup are not offered on the Translations screen.
- **CSP:** no inline scripts or styles. The JS dictionary is a `<script type="application/json" nonce=…>` block.
- **Key style:** `{group}.{area}.{name}` in snake_case, e.g. `folio.assets.replace`, `folio.common.save`. Parameters use `:name`. Plurals use the existing `choice()` syntax.
- **Gates:**
  - `vendor/bin/phpunit` (baseline v0.36.0: 3410 tests, 99 deprecations; add none);
  - `composer test:mysql`;
  - smeyer.me and smart-stack.works suites;
  - `preflow translations:check` clean for en, de and fr;
  - don't touch holz-objekte.org;
  - no merge, push or tag without the owner's go-ahead.

## Rulings made in this plan (for the owner to check)

1. **A translator is always booted when `preflow/i18n` is installed,** even without a site `lang/` folder or `config/i18n.php`. Without either, it gets `t()` but no `LocaleMiddleware`, so the frontend is unchanged.
2. **Validation messages get a `validation` group (`public`).** The validator translates only when it is given a translator: Folio passes one for admin forms. A site's frontend forms keep their English messages until the site opts in, so public pages don't change.
3. **The user's interface language is stored by Folio** per auth id in its own table (`folio_user_preferences`, like `ExtensionState`). Config users and record users work the same way, and the choice survives sign-out.
4. **Every admin user can switch language from the sidebar footer**, a POST to `{prefix}/_locale`, next to the theme switch. Record users also see it on their account page.
5. **Each task delivers en, de and fr for the keys it creates.** Task 8 audits; it doesn't translate from scratch.

## Review Focus

- **A config user (smeyer.me's `hi@smeyer.me`) picks French:** it is stored without a user record, kept after signing out and in, and another user still sees their own language (Task 2).
- **Parameters and plurals in de and fr:** `:count`/`:name` replaced in any word order. "1 Datei" / "2 Dateien", "1 fichier" / "2 fichiers" via `choice()` (Task 1 for the mechanism, Task 3 for Folio's counts).
- **A fresh site with neither `lang/` nor `config/i18n.php`** still shows the translated admin in a user's chosen language, and the frontend gets no locale middleware (Tasks 1–2).
- **A public page with overrides present and a user's admin preference set** renders byte-identically, in the frontend's own locale (Tasks 2 and 6).
- **A label in a model file given as a map without the user's language** (`{"en": "Apps", "de": "Apps"}` for a French user) shows the site default's text, not a key or a blank (Task 5).

---

### Task 1: i18n core — discovery, scopes, fallback chain, overrides interface, label maps, check command

**Files:**
- Create:
  - `packages/i18n/src/TranslationRegistry.php` (discovery from `installed.json`);
  - `packages/i18n/src/TranslationOverrides.php` (interface) and `NoTranslationOverrides.php`;
  - `packages/i18n/src/Labels.php`;
  - `packages/i18n/src/Console/TranslationsCheckCommand.php`, listed in `packages/i18n/composer.json` `extra.preflow.commands`.
- Modify:
  - `packages/i18n/src/Translator.php`;
  - `packages/core/src/Application.php` (`bootI18n()`, Ruling 1).
- Test: `packages/i18n/tests/TranslationRegistryTest.php`, `TranslatorLayersTest.php`, `LabelsTest.php`, `Console/TranslationsCheckCommandTest.php`; `packages/core/tests/…` for boot.

**Interfaces — Produces:**
- **`TranslationRegistry::fromInstalled(string $installedJson): self`** reads each package's `extra.preflow.translations`:
  - the value is `{group: {path, scope}}` or `{group: "path"}` (scope `admin`);
  - `path` is relative to the package's install path.
  - `groups(): array<string, array{path: string, scope: 'admin'|'public'}>`; `scope(string $group): ?string`; `apply(Translator $t): void` calls `addDefaults()` for each.
  - An invalid scope or a missing folder is skipped with an `error_log` line. It never breaks boot.
- **`Translator`:**
  - `setScopes(array<string, string> $scopes)`;
  - `setOverrides(TranslationOverrides $o)` and `useOverrides(bool $on)` (off by default; Folio turns it on for admin requests);
  - a fallback chain of the locale, then the constructor `$fallbackLocale`, then `'en'`;
  - `has(string $key, ?string $locale = null): bool`;
  - `groups(): list<string>`;
  - `entries(string $group, string $locale): array<string, string>` (flattened dot keys, for the check command and Task 6).
- **`interface TranslationOverrides`:** `get(string $locale, string $key): ?string`. `NoTranslationOverrides` returns null. Overrides are consulted only when `useOverrides(true)` and the key's group scope is `admin`.
- **`Labels::pick(string|array|null $value, string $locale, string $default): ?string`:**
  - a string returns itself;
  - a map returns `$value[$locale] ?? $value[$default] ?? $value['en'] ?? first`;
  - null returns null.
- **`Labels::resolve(Translator $t, string $key, string|array|null $value): ?string`:** the translator's key wins (an override or a site file), then `pick()` in the translator's locale with the site default.
- **`preflow translations:check [--group=G]`:**
  - prints keys missing per group and locale (compared with `en`);
  - prints keys used in package and site templates (`t('…')`) and model files that exist nowhere;
  - exits 1 when anything is missing.

- [ ] **Step 1: Failing tests.**
  - **Registry:** a fixture `installed.json` with packages declaring `{"media": {"path": "lang", "scope": "admin"}}`, `{"members": {"path": "lang", "scope": "public"}}` and `{"x": "lang"}` gives those groups and scopes (`x` is admin). `apply()` makes `t->get('media.k')` resolve from the fixture.
  - **Precedence:** with overrides on, an admin-group key returns the override, then the site file, then the package. With overrides on and a public-group key, the override is ignored. With overrides off, the override is ignored for every group.
  - **Fallback:** a key only in `en` reached from locale `fr` with site default `de` returns the `en` text. Only in `de`: `fr` returns `de`.
  - **Parameters and plurals:** `get('g.hello', ['name' => 'Ana'])` in `de` → `Hallo Ana`; `choice('g.files', 1)` → `1 Datei`, `choice('g.files', 2)` → `2 Dateien`; in `fr`: `1 fichier`, `2 fichiers`.
  - **`Labels::pick`:**
    - `('Apps', 'fr', 'de')` → `Apps`;
    - `(['en' => 'Apps', 'de' => 'Anwendungen'], 'fr', 'de')` → `Anwendungen`;
    - `(['en' => 'Apps'], 'fr', 'de')` → `Apps`.
  - **The check command** on a fixture with a key missing in `fr` and a template using `t('g.nope')` prints both and exits 1. A complete fixture exits 0.
  - **Boot:** an app without `lang/` and `config/i18n.php`, with an installed package declaring translations, has a `Translator` in the container and no `LocaleMiddleware`. An app with `lang/` behaves as today.
- [ ] **Step 2:** `vendor/bin/phpunit packages/i18n packages/core` fails.
- [ ] **Step 3:** Implement. `bootI18n()` builds the registry from `{basePath}/vendor/composer/installed.json`, applies it, sets scopes and binds `TranslationRegistry`. Replace the hand-made `addDefaults()` calls in Folio, members and rbac with `extra.preflow.translations` declarations in their `composer.json`: folio `admin`, rbac `admin`, members `public` for now (Task 7 splits it).
- [ ] **Step 4:** `vendor/bin/phpunit packages/i18n packages/core packages/folio packages/members packages/rbac` passes.
- [ ] **Step 5:** Commit `feat(i18n): discovered package translations, scopes, fallback chain, overrides, labels, check command`.

### Task 2: The user's interface language

**Files:**
- Create:
  - `packages/folio/src/Users/UserPreferences.php` (table `folio_user_preferences`, id = auth id, field `locale`; the `ExtensionState` pattern);
  - `packages/folio/src/Http/AdminLocaleMiddleware.php`;
  - `packages/folio/src/Http/LocaleController.php`.
- Modify:
  - `FolioRoutes.php`: `POST {prefix}/_locale` (signed in, no permission);
  - `FolioServiceProvider.php`: register the middleware on admin routes, config `folio.locale`;
  - `templates/admin/_layout.twig`: the sidebar footer switch;
  - `AccountController` and its template: the language select;
  - `Users/StaffMailer.php`: the recipient's language.
- Test: `packages/folio/tests/Integration/InterfaceLocaleTest.php`.

**Interfaces:**
- Consumes: Task 1 (`Translator::useOverrides`, `setLocale`, `groups`).
- Produces:
  - **`UserPreferences::locale(string $authId): ?string`** and **`setLocale(string $authId, ?string $locale): void`**.
  - **`AdminLocaleMiddleware`:** on admin requests it sets `Translator::setLocale()` to the user's preference, else `folio.locale` (default `app.locale`), else `en`. It turns overrides on, and on the way out it restores the previous locale and overrides off. Unauthenticated admin pages (sign-in, password) use the browser's `Accept-Language` when it names an offered language, else the default.
  - **`interfaceLocales(): list<string>`** on the middleware (or a small `InterfaceLocales` service): the locales with Folio's group delivered or present in the site's `lang/`, offered in the switch with their own names ("Deutsch", "Français", "English").
  - The template global `interface_locale`, and `<html lang="{{ interface_locale }}">` in the admin layout.

- [ ] **Step 1: Failing tests.**
  - A staff user posts `locale=fr` to `/_locale`: it gets a 302 back to the referring admin URL (same-origin only, else the dashboard), and `UserPreferences::locale(id)` is `fr`.
  - The next admin page renders `lang="fr"` with a known French string from the layout. A second user in the same test still gets the default.
  - **A config user** (ConfigUserProvider) does the same, and the choice survives signing out and in.
  - `locale=xx` (not offered) gives a 422 and nothing is stored.
  - The sign-in page with `Accept-Language: fr-CH,fr;q=0.9` renders in French.
  - **A public page** after the user set French renders byte-identically to before, in the frontend's locale.
  - An invitation mail to a user whose preference is `de` uses the German text.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement. Turn Folio's staff mail texts into standard German spelling ("Freundliche Grüße").
- [ ] **Step 4:** `vendor/bin/phpunit packages/folio packages/rbac` passes.
- [ ] **Step 5:** Commit `feat(folio): each user picks the admin language`.

### Task 3: Folio's texts, part 1 — shell, content, auth, mails, JS dictionary, formats

**Files:**
- Modify:
  - `packages/folio/templates/admin/` layout, nav, dashboard, content list, edit, form fields, filters, bulk bar, publishing, revisions, preview, block editor, and the sign-in, password, invite and account pages;
  - matching controllers and helpers in `packages/folio/src/Http`, `src/Content`, `src/Field`, `src/Publishing` (notices, errors, empty states, column titles, bulk labels, export header row);
  - `packages/folio/assets/admin.js` and `preview-editor.js`: every string from the dictionary;
  - `packages/folio/lang/{en,de,fr}/folio.php`.
- Create: `packages/folio/templates/admin/_i18n.twig` (the JSON dictionary block, included in `_layout.twig` and `_drawer_layout.twig`); Twig filters `folio_date` / `folio_number` (in `FolioServiceProvider`).
- Test: `packages/folio/tests/Integration/AdminTranslationTest.php` (part 1).

**Interfaces:**
- Consumes: Tasks 1–2.
- Produces:
  - **The JS dictionary:** `<script type="application/json" id="folio-i18n" nonce="…">{…}</script>` with the `folio.js.*` keys for the current locale. `admin.js` reads it once with `folioT(key, params)`, with the same `:param` replacement.
  - **`folio_date(value, style = 'medium')` / `folio_number(value)`:** `IntlDateFormatter` / `NumberFormatter` in the interface locale when ext-intl is present, else the per-language patterns `folio.format.date` / `folio.format.datetime` from the language file.
  - **Shared keys** under `folio.common.*` (save, cancel, delete, edit, back, search, filter, yes, no, …) for Task 4 and the other packages to reuse.

- [ ] **Step 1: Failing tests.** `AdminTranslationTest` signs in a user with preference `de`, then `fr`. It renders the dashboard, a content list with filters and the bulk bar, a record edit form, revisions, sign-in, forgot-password and the account page. For each:
  - no raw key appears (a regex for `folio\.[a-z_]+\.`);
  - none of a list of English source strings from these screens appears (at least: "Save", "Delete", "Search", "Dashboard", "Filter", "Sign in", "Forgot your password?");
  - a known German or French string appears.
  - Plus:
    - the JSON dictionary block is present, has the nonce, and holds `folio.js.confirm_delete` in the user's language;
    - `admin.js` contains no English UI sentence (grep test over a short list: `'Close'`, `'Remove'`, `'Undo'`, `'Copied'`, `'Delete this record?'`);
    - `folio_date('2026-10-26T14:05:00Z')` renders `26.10.2026` in `de` and `26/10/2026` in `fr` (date part), with and without intl (the test forces the fallback);
    - "3 Einträge" / "3 entrées" through `choice()` on a list count.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement. Write en, de and fr for every key ("du", standard spelling; French per the constraints).
- [ ] **Step 4:** `vendor/bin/phpunit packages/folio packages/rbac` passes, and `preflow translations:check --group=folio` (on the test app) shows no missing keys.
- [ ] **Step 5:** Commit `feat(folio): admin shell, content screens, auth and mails translated`.

### Task 4: Folio's texts, part 2 — assets, users, extensions, settings, cache, errors

**Files:**
- Modify:
  - `packages/folio/templates/admin/assets/*` (library, detail page, image editor, picker);
  - users and roles screens, extensions, site settings, cache screen, 403/404/error pages;
  - their controllers (`AssetLibraryController`, `CacheController`, `ExtensionsController`, users and roles controllers, `Forbidden`);
  - `packages/folio/assets/image-editor.js` (dictionary);
  - the core-produced texts Folio shows: the media note `needs Imagick for colour-accurate variants` and `AssetUploadException`/`InvalidArgumentException` messages from `MediaLibrary`. Folio maps them to keys; the core keeps its English messages for logs and CLI.
- Test: `AdminTranslationTest` part 2.

**Interfaces:** consumes Task 3's `folio.common.*`, the dictionary, `folio_date`/`folio_number`.

- [ ] **Step 1: Failing tests.** The same assertions as Task 3 for:
  - the asset library (grid and list), the asset detail page, the Edit image screen and the picker drawer;
  - users list and edit, roles, extensions, site settings, the cache screen;
  - a 403 page and a 404 inside the admin.
  - The English source-string list adds at least "Replace", "Earlier versions", "Edit image", "Rotate left", "Clear cache", "Not used anywhere.".
  - "3 Dateien" / "3 fichiers" in the library count.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement, with en, de and fr.
- [ ] **Step 4:** `vendor/bin/phpunit packages/folio packages/rbac packages/media` passes, and `translations:check --group=folio` is clean.
- [ ] **Step 5:** Commit `feat(folio): assets, users, extensions, settings and cache translated`.

### Task 5: Site and package model labels

**Files:**
- Modify:
  - `packages/data/src/TypeRegistry.php` and `TypeFieldDefinition.php`: `label`/`help` accept a string or a map. Keep `?string $label` (the default-language text) and add `array $labels = []`, `array $helps = []`. Select options keep their map shape `{value: label|{locale: label}}`.
  - `packages/folio/src/Content/TypeCatalog.php`: the type label map and the nav section names.
  - `ListView`, form rendering (`FieldContext`/field types), filters, bulk actions, the export header row: all display texts go through one Folio helper that calls `Labels::resolve()`.
  - `packages/codes/src/models/*.json` and `packages/members/src/models/*.json`: no change to the JSON. Their labels get keys in their own groups.
- Create: `packages/folio/src/Content/DisplayLabels.php`.
- Test: `packages/folio/tests/Integration/ModelLabelsTest.php`, `packages/data/tests/TypeRegistryLabelsTest.php`.

**Interfaces:**
- Consumes: Task 1 (`Labels::resolve`).
- Produces:
  - **`DisplayLabels::type(string $type): string`**, **`field(string $type, string $field): string`**, **`help(string $type, string $field): ?string`**, **`option(string $type, string $field, string $value): string`**.
  - **Keys:**
    - a site's own types: `site.types.{type}.label`, `site.fields.{type}.{field}.label`, `site.fields.{type}.{field}.help`, `site.options.{type}.{field}.{value}`;
    - a type a package or extension ships: the same shape under that package's group, e.g. `codes.types.code.label`, `codes.fields.code.purpose.label`.
  - **Which group a type belongs to:** Folio knows each type's source (site models path, package, extension). A type from a package or extension uses that package's declared group; otherwise `site`.
  - **Order:** key (override > site file > package file) > map in the model file (user locale > site default > en) > plain string > humanized field name (as today).

- [ ] **Step 1: Failing tests.**
  - **smeyer.me-like `app.json` with `"label": "Apps"`:** French user → `Apps`. With `lang/fr/site.php` `types.app.label = 'Applications'` → `Applications`.
  - **The label as a map** `{"en": "Apps", "de": "Anwendungen"}`: German → `Anwendungen`; French with site default `de` → `Anwendungen`.
  - **Field label, help and select option labels** follow the same order in the edit form, the list column header, the filter and the bulk action.
  - **A codes type's field label** shows the codes group's German text for a German user.
  - **The CSV/XLSX export header row** uses the resolved labels.
  - **Data:** a map label parses with `label` = the `en` entry, else the first; old string labels are unchanged; `config` no longer receives `labels`.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement. Add `codes` and `members` label keys in en, de and fr (Task 7 declares their groups; this task adds the keys to their `lang/` files).
- [ ] **Step 4:** `vendor/bin/phpunit packages/data packages/folio packages/codes packages/members` passes.
- [ ] **Step 5:** Commit `feat: translatable model labels for sites and packages`.

### Task 6: Admin overrides — the Translations screen

**Files:**
- Create:
  - `packages/folio/src/Translations/StoredOverrides.php` (implements `TranslationOverrides`; table `folio_translation_overrides`; id = `{locale}:{key}`; fields `locale`, `key`, `text`, `updated_by`, `updated_at`);
  - `packages/folio/src/Translations/OverridesCache.php` (implements `Preflow\Core\Cache\CacheSource`: compiled `storage/cache/folio-translations-{locale}.php`, key `folio-translations`, label from `folio.cache.translations`);
  - `packages/folio/src/Http/TranslationsController.php`;
  - `templates/admin/translations/index.twig`.
- Modify:
  - `FolioRoutes.php`: `GET {prefix}/translations`, `POST {prefix}/translations` (save one), `POST {prefix}/translations/reset`;
  - `FolioPermissionMiddleware`: permission `translations.edit`;
  - rbac default roles: the super admin gets it;
  - `packages/rbac/tests/TwoRolesTest.php` rows;
  - `AdminNav`: Settings → Translations;
  - Folio's export/import (`src/Transfer`): it carries the overrides table.
- Test: `packages/folio/tests/Integration/TranslationOverridesTest.php`.

**Interfaces:**
- Consumes: Task 1 (`TranslationOverrides`, `Translator::entries`, `groups`, scopes), Task 2 (overrides on for admin requests).
- Produces: `StoredOverrides::set(string $locale, string $key, ?string $text, ?string $by): void` (null or `''` removes), `all(string $locale): array<string, string>`.

**Screen:**
- **Filters:** group (admin-scope groups plus `site`), language, search over keys and texts.
- **Rows:** the key, the delivered text, the site file's text when it differs, and the override field with Save and Reset.
- 50 rows per page. Keys whose `en` text contains `<` or `>` are not listed.

- [ ] **Step 1: Failing tests.**
  - Saving `folio.common.save` = `Sichern` for `de` makes a German user's admin show `Sichern`. A French user's admin is unchanged.
  - Reset brings back the delivered text.
  - A text containing `<b>` is refused with 422 and nothing is stored. A markup key isn't listed.
  - A public-group key isn't listed, and an override row for it (inserted directly) has no effect on a members page, which stays byte-identical.
  - The compiled cache file appears after the first admin request, is removed by Settings → Cache and by every save, and the screen lists the source.
  - A user without `translations.edit` gets 403 on all three routes. CSRF is required.
  - Export then import into a fresh site keeps the overrides.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement, with en, de and fr for the screen's own texts.
- [ ] **Step 4:** `vendor/bin/phpunit packages/folio packages/rbac` passes.
- [ ] **Step 5:** Commit `feat(folio): Translations screen for admin overrides`.

### Task 7: The other packages — members, codes, rbac, validation, media

**Files:**
- Modify:
  - `packages/members`: split the admin-facing texts (Folio screens, list labels, staff notices, `Admission` messages shown in Folio) into a new group `members-admin` (`lang/{en,de,fr}/members-admin.php`, scope `admin`, "du"). `members` stays `public`, keeps "Sie" and keeps its strings unchanged.
  - `packages/codes`: `lang/{en,de,fr}/codes.php` (scope `admin`) for `redemptions.twig`, its Folio field and list texts, and Task 5's model label keys. Declare in `composer.json`.
  - `packages/rbac`: its existing `lang/` declared (Task 1); complete de and fr; roles and permission names on Folio's screens through keys (`rbac.permissions.assets_edit` …).
  - `packages/validation`: `lang/{en,de,fr}/validation.php` (scope `public`, Ruling 2). Each rule returns a key plus parameters. `Validator`/`ValidatorFactory` accept an optional `Translator`: with one, messages are translated; without, the English text is exactly as today. Folio passes the translator for admin forms.
  - `packages/media`: no admin templates. Its user-facing exception messages stay English (logs and CLI); Folio maps them (Task 4).
- Test: per package (`MembersAdminTranslationTest`, `CodesTranslationTest`, `ValidationTranslationTest`), plus the byte-identity test for a members public page and a frontend form's validation message.

- [ ] **Step 1: Failing tests.**
  - The members admin list and member edit screen in German show `members-admin` texts in "du". The members registration page in German is byte-identical to v0.36.0 ("Sie").
  - The codes redemptions screen in French shows French texts.
  - Folio's roles screen shows permission names in German.
  - A required field left empty in a Folio form shows "Dieses Feld ist erforderlich." for a German user. A frontend form without a translator still shows "This field is required.".
  - `translations:check` is clean for `members`, `members-admin`, `codes`, `rbac` and `validation`.
- [ ] **Step 2:** The tests fail.
- [ ] **Step 3:** Implement, with en, de and fr.
- [ ] **Step 4:** `vendor/bin/phpunit packages/members packages/codes packages/rbac packages/validation packages/folio` passes.
- [ ] **Step 5:** Commit `feat: members, codes, rbac and validation translated for the admin`.

### Task 8: Audit, glossary, docs and release preparation

**Files:**
- Create:
  - `docs/translations-glossary.md`: the fixed terms (asset, record, page, entry, publish, draft, working copy, version, revision, role, permission, extension, member, code …) in en, de and fr;
  - `docs/superpowers/plans/2026-10-26-release-0.37-checklist.md` (the 0.36 checklist's shape).
- Modify:
  - `packages/i18n/README.md`: declaring translations, scopes, precedence, overrides, `Labels`, `translations:check`;
  - `packages/folio/README.md`: the language switch, the Translations screen, `translations.edit`, model label maps;
  - `ROADMAP.md`;
  - the Composer constraints for packages whose requirements changed (folio, members, codes, rbac and validation on `preflow/i18n ^0.37`), with their constraint tests;
  - any delivered de/fr text that the audit fixes.
- Test: `packages/folio/tests/Integration/AllScreensTranslatedTest.php`. It walks every admin GET route in the test app (from `FolioRoutes` plus the packages' admin routes) as a German and as a French user. It fails on any raw key or any string from a combined English source list (collected from the `en` files: every `en` value of 12 or more characters that differs from its de/fr value).

- [ ] **Step 1:** Write `AllScreensTranslatedTest` and run it. Fix every gap it finds in the owning package's language file (texts only; code gaps go back as findings).
- [ ] **Step 2:** Read all de and fr files against the glossary. Fix inconsistent terms, wrong register ("Sie" in admin German, missing "vous"), and Swiss spelling.
- [ ] **Step 3:** Docs and checklist. The upgrade notes must mention:
  - the `extra.preflow.translations` declaration, which replaces `addDefaults()` calls;
  - the translator booting without `lang/`;
  - the new `folio_user_preferences` and `folio_translation_overrides` tables (`preflow migrate` on SQL storage);
  - the `translations.edit` permission;
  - validation's opt-in translator;
  - the members split (`members-admin`);
  - standard German spelling in staff mails.
- [ ] **Step 4:** Gates. Record the counts in the checklist:
  - `vendor/bin/phpunit` with no new deprecations;
  - `composer test:mysql`;
  - `preflow translations:check` clean;
  - smeyer.me and smart-stack.works suites (`find storage/cache -name 'folio-*.cache' -delete` first; don't modify site files).
- [ ] **Step 5:** Commit `chore: prepare 0.37.0`.
