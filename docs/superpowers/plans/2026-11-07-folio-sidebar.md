# Folio sidebar: collapsible sections — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In Folio's admin sidebar, each section with a heading opens and closes. The state is remembered per person, the
current screen's section always stays open, a closed section shows the sum of its badges, and a site can set which
sections start closed.

**Architecture:** All changes are in `packages/folio`:
- `UserPreferences` stores a `nav` map beside the language.
- `AdminNav::sections()` gains `name`, `badge` and `open` per section, from the saved map, the site default and the
  active item.
- A small endpoint saves one section at a time.
- The layout renders `<details>`, and `admin.js` posts each toggle.

**Tech Stack:** PHP 8.4+, PHPUnit 11.5 (monorepo root `vendor/bin/phpunit`), Twig, plain JS with `node --test` (`packages/folio/tests/js`, `fake-dom.mjs`).

**Spec:** `/Users/smyr/Sites/gbits/preflow-framework/docs/superpowers/specs/2026-11-07-folio-sidebar-design.md` (approved 2026-11-07)

## Global Constraints

- Folio only; no change in other packages and no new interface for sites.
- Section and item order and permissions are unchanged (`AdminNav::sectionOrder()` stays as it is).
- `sections()` only gains keys; the existing ones (`label`, `items`, and per item `label`, `url`, `icon`, `active`, `badge`) keep their meaning.
- No inline script without the nonce. JS goes in `assets/admin.js`, CSS in `assets/admin.css`, matching the 0.48.4 visual refresh.
- Shipped default `nav.collapsed` = `['library', 'settings']`. A missing value means `[]`. A value that isn't a list of strings is ignored, with no error.
- Any new texts go in Folio's translation files in de, en and fr (`TranslationsDeclarationTest` must stay green).
- Release as Folio minor **0.57.0** (all packages share the monorepo tag), with a ROADMAP entry. Then upgrade and deploy
  the owner's 5 sites (holz-objekte.org, BG Genius, smart-stack.works, smeyer.me, languagecorner.ch) after a backup each,
  with their version pins bumped to `^0.57`.

## Review Focus

1. **A person who chooses "browser default" language must keep their saved sections.** Test in Task 1.
2. **Old preferences table without a `nav` column**, as on sites that haven't written since the upgrade: reading must
   return "nothing saved" and never throw. Test in Task 1.
3. **A saved section name that no longer exists** (the extension was removed) is ignored when rendering. Test in Task 2.
4. **The save endpoint called by someone signed out, or without a CSRF token,** or with a section name that is too long
   or has odd characters: refused, nothing stored. Test in Task 3.
5. **Two toggles of different sections from two tabs** both end up saved, since each save changes only its own key.
   Test in Task 1.

---

### Task 1: Section state in the user preferences

**Files:**
- Modify: `packages/folio/src/Users/UserPreferences.php`
- Test: `packages/folio/tests/Users/UserPreferencesTest.php`

**Interfaces:**
- Produces:
  - `UserPreferences::navSections(string $authId): array<string, bool>`, a map of section name to open (true) or closed (false); `[]` when nothing is saved or there is no table or column yet.
  - `UserPreferences::setNavSection(string $authId, string $section, bool $open): void`, which changes only that key, keeping the language and the other sections.
  - `setLocale(string $authId, ?string $locale)`: null now clears only the language. The row is deleted only when both the language and the nav map are empty.

- [ ] **Step 1: Write the failing tests**
  - `test_nav_sections_are_stored_per_auth_id`: `setNavSection('u1','library',true)`, then `setNavSection('u1','settings',false)` → `navSections('u1') === ['library'=>true,'settings'=>false]`; `navSections('u2') === []`.
  - `test_saving_one_section_keeps_the_others_and_the_locale`: set locale `de`, set `library` true, set `settings` false, set `library` false → map `['library'=>false,'settings'=>false]`, `locale('u1') === 'de'`.
  - `test_clearing_the_locale_keeps_nav_sections`: set `library` true and locale `fr`, then `setLocale('u1', null)` → `locale('u1') === null` and `navSections('u1') === ['library'=>true]`.
  - `test_the_row_goes_when_nothing_is_left`: locale `de` only, then `setLocale(null)` → `findIn` returns null.
  - `test_nav_sections_read_from_an_old_table_without_the_column`: create the table with only `id` and `locale` (as 0.56 did) and insert a row → `navSections` returns `[]` with no exception, and `locale` still works.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/folio/tests/Users/UserPreferencesTest.php`. Expected: FAIL (unknown methods).
- [ ] **Step 3: Implement.**
  - Add the field `'nav' => TypeFieldDefinition('nav', 'json')`. If the data layer has no json field type, use `text` and encode/decode JSON yourself.
  - Ignore non-bool values when reading.
  - Writing goes through `ensureTable()`, which adds the column via `RecordTableSync`.
  - Reading catches a missing table or column the way `locale()` already does: a PDO error → `[]`.
- [ ] **Step 4:** Run it again. Expected: PASS. Then run all of `packages/folio/tests/Users`.
- [ ] **Step 5:** Commit: `feat(folio): remember sidebar sections per user`

### Task 2: Sections know their name, badge sum and open state

**Files:**
- Modify: `packages/folio/src/Http/AdminNav.php`, `packages/folio/src/FolioServiceProvider.php` (the AdminNav singleton, near line 513)
- Test: `packages/folio/tests/Http/AdminNavTest.php`

**Interfaces:**
- Consumes: `UserPreferences::navSections()` (Task 1).
- Produces: each element of `AdminNav::sections()` gains:
  - `name` (string; the section key, `''` for dashboard);
  - `badge` (?int: sum of item badges > 0, else null);
  - `open` (bool).
  - A new setter `AdminNav::setOpenState(\Closure $saved, array $collapsedDefaults): void`. `$saved` is `fn(): array<string,bool>`, the current person's map, asked once per render; `$collapsedDefaults` is a list of section names.
  - Without a call to the setter, every section is `open = true`, as today.

- [ ] **Step 1: Write the failing tests**
  - `test_sections_carry_their_name_and_badge_sum`: two items in `settings` with badges 2 and 3 → that section has `name === 'settings'`, `badge === 5`; a section without badges has `badge === null`.
  - `test_open_follows_saved_state_then_default_then_open`: defaults `['library','settings']`, saved `['library'=>true,'content'=>false]` → library open, settings closed, content closed, pages open.
  - `test_the_active_items_section_is_always_open`: saved `['settings'=>false]`, current path is a settings item URL → settings `open === true`.
  - `test_no_active_item_forces_nothing_open`: current path `/folio/profile` with no match → saved states apply unchanged.
  - `test_unknown_saved_sections_are_ignored`: saved `['gone'=>false]` → no error, and no section named `gone`.
  - `test_without_open_state_every_section_is_open`.
  - `test_a_malformed_collapsed_default_is_ignored`: put `'nav' => ['collapsed' => 'settings']` in config through the provider → no error, and settings is open.
- [ ] **Step 2:** Run `vendor/bin/phpunit packages/folio/tests/Http/AdminNavTest.php`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - Add `setOpenState` and extend `sections()`.
  - In the provider, after building AdminNav, call `setOpenState`:
    - the closure reads the signed-in user's auth id from the current request or user (the same way `AdminLocaleMiddleware` finds it) and returns `[]` when there is none;
    - the defaults come from `config('folio.nav.collapsed')`, defaulting to `['library','settings']`, kept only when it is a list of strings.
  - Document `nav.collapsed` in the commented sample of `config/folio.php` that Folio's skeleton or README ships (wherever the other folio options are documented).
- [ ] **Step 4:** Run it again. Expected: PASS.
- [ ] **Step 5:** Commit: `feat(folio): sidebar sections know name, badge sum and open state`

### Task 3: Endpoint that saves one section

**Files:**
- Create: `packages/folio/src/Http/NavPreferenceController.php`
- Modify: `packages/folio/src/Routing/FolioRoutes.php` (next to `_locale`, line ~52), plus the provider binding and the permission rule if routes need one (the `_locale` route needs a sign-in only).
- Test: `packages/folio/tests/Http/NavPreferenceControllerTest.php`

**Interfaces:**
- Consumes: `UserPreferences::setNavSection()`.
- Produces: `POST {prefix}/_prefs/nav` with body `section=<name>&open=1|0`.
  - **204** when saved.
  - **422** when `section` doesn't match `^[a-z0-9_]{1,64}$` or `open` isn't `0`/`1`.
  - **302** to login when signed out (as `LocaleController`).
  - **403** from the existing CSRF middleware without a token.

- [ ] **Step 1: Write the failing tests**
  - `test_a_signed_in_user_saves_a_section`: 204, then `navSections` equals `['settings'=>false]`.
  - `test_signed_out_is_sent_to_login_and_nothing_is_saved`.
  - `test_invalid_section_or_flag_is_refused`: `'../x'`, a string of 65 `a`, `open=yes` → 422, nothing saved.
  - `test_without_csrf_token_it_is_refused`: drive it through the full app the way other Folio HTTP tests do CSRF.
- [ ] **Step 2:** Run it. Expected: FAIL (404 route).
- [ ] **Step 3: Implement.** Register the route before `{type}`, which would match it, like `_locale`.
- [ ] **Step 4:** Run it. Expected: PASS. Then run all of `packages/folio/tests/Routing` and `packages/folio/tests/Permissions`.
- [ ] **Step 5:** Commit: `feat(folio): save a sidebar section's state`

### Task 4: Sidebar markup, styles and script

**Files:**
- Modify: `packages/folio/templates/admin/_layout.twig` (sidebar lines 49–63), `packages/folio/assets/admin.css`, `packages/folio/assets/admin.js`, and Folio's de/en/fr text files if new texts are needed.
- Test: `packages/folio/tests/Http/` (a layout rendering test, e.g. extend the one covering the sidebar, or add `SidebarSectionsTest.php`), `packages/folio/tests/js/nav-sections.test.mjs`

**Interfaces:**
- Consumes: section `name`, `badge`, `open` (Task 2), and the endpoint (Task 3). The endpoint URL reaches the script via a `data-nav-save="{prefix}/_prefs/nav"` attribute on `nav.folio-nav`. The CSRF token is read the same way existing `admin.js` fetches do; check how `ai-translate.js` or the choose-assets code sends it.
- Produces: every section with a heading renders as `<details class="folio-nav-section" data-section="{name}" {open}>`, holding a `<summary>` with `<span class="folio-nav-label">` and, when the badge is set, `<span class="folio-nav-badge folio-nav-section-badge" aria-label="…waiting…">{badge}</span>`, followed by the items. Dashboard (no heading) renders as today.

- [ ] **Step 1: Write the failing tests**
  - PHP: render the admin layout for a signed-in user with saved `settings` closed and a badge in settings:
    - assert `details.folio-nav-section[data-section="settings"]` has no `open` attribute;
    - assert its `summary` holds the summed badge;
    - assert the section of the current page has `open`;
    - assert the dashboard link is not inside a `details`;
    - assert `nav.folio-nav` carries `data-nav-save`.
  - JS (`node --test`, fake-dom): load `admin.js` with that markup; dispatching `toggle` on a closed `details` that becomes open posts `section=settings&open=1` to the `data-nav-save` URL with the CSRF token. A failed fetch (rejected promise) throws nothing and leaves the details as they are.
- [ ] **Step 2:** Run `vendor/bin/phpunit --filter Sidebar` and `node --test packages/folio/tests/js/nav-sections.test.mjs`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - The summed badge shows only on closed sections, via CSS `details:not([open]) > summary .folio-nav-section-badge`; it is hidden when open.
  - Give `summary` the folio-nav-label look, with a small open/closed marker. Remove the default marker.
  - Check the mobile drawer: if the sidebar markup is rendered twice, render both copies from the same data and update the twin when one toggles.
  - Keyboard: native `details`/`summary` covers Tab plus Enter/Space. If a screen reader announces only the heading, add an `aria-label` text such as "Settings, section" in de/en/fr.
- [ ] **Step 4:** Run both again. Expected: PASS. Then run the full Folio suite (`vendor/bin/phpunit packages/folio`) and `node --test packages/folio/tests/js`.
- [ ] **Step 5: Check it by hand in a browser.**
  - On a local site (smart-stack.works via https://smartstack.dev.smyr, or `php -S`), close two sections, reload, and check them in a second browser profile with the same login.
  - Check that a pending badge shows on a closed section.
  - Check Tab and Enter, and a narrow window.
  - Stop any server you start by its PID.
- [ ] **Step 6:** Commit: `feat(folio): collapsible sidebar sections`

### Task 5: Release 0.57.0 and the sites

- [ ] **Step 1:** ROADMAP: a 0.57.0 entry describing the feature and the `nav.collapsed` option. Update the "Current State" line with the version and the test count. Run the full monorepo suite (it takes ~10 min). Commit: `docs: 0.57.0 Folio sidebar sections`.
- [ ] **Step 2:** Tag `v0.57.0`, then push main and the tag. The packages split to Packagist on tag push, as in earlier releases.
- [ ] **Step 3: Each of the 5 sites.**
  - Back up first (DB and/or data folder, as done for 0.56.1).
  - Run `composer update 'preflow/*'` and bump the server constraint pin to `^0.57`.
  - Run the site's `composer test`, then commit, push and run `vendor/bin/dep deploy`.
  - Check that the site's pages answer 200 and that `/folio/login` loads.
  - holz-objekte.org uses Packagist constraints `^0.57` and its pin lives in `tests/Unit/DeployConfigTest.php`. Don't run two holz suites at once.
- [ ] **Step 4:** Update the memory notes on the current version.
