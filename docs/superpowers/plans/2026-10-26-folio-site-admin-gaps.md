# Folio Site Admin Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the Folio and i18n gaps holz-objekte.org's staff admin (its step 9) needs, merge-ready for the joint v0.38.0 release with the members split:
- staff edit a site's own text groups (mails included) on the Translations screen;
- an extension screen shows a sidebar count;
- screen labels follow the staff member's language;
- a `#[FormPanel]` can hide itself, and a `#[ListColumn]` cell can be a link.

**Architecture:**
- **i18n:** the `Translator` learns "always overridden" groups, whose overrides apply on every request (public, mail, console), not only while Folio has overrides on.
- **Folio:** reads the site's list from `config/folio.php`, offers those groups on the Translations screen in the site's content languages, lets `#[NavBadge]` sit on a `#[FolioScreen]`, resolves screen labels with `FolioText::label()`, skips a panel whose method returns null, and renders a new `Link` cell value next to `Badge`.

**Tech Stack:** PHP 8.4, PHPUnit 11, Twig. The monorepo is `packages/*`, with tests from the repo root (`vendor/bin/phpunit`).

**Spec:**
- Binding surfaces: `/private/tmp/claude-501/-Users-smyr-Sites-gbits-smeyer-me/05183df0-593a-496c-9430-5f291b7fca8c/scratchpad/step9/contract.md` (A1–A3; A4 is deferred, see the end). Task 1 copies it as `docs/superpowers/specs/2026-10-26-site-admin-gaps-contract.md` and appends A5 (panel null) and A6 (link cell) as Tasks 5 and 6 define them.
- Why: `/Users/smyr/Sites/gbits/holz-objekte.org/docs/superpowers/specs/2026-10-26-staff-admin-design.md`, Part A (Parts B–F are the site's own plan; Part E's "company (link), object (link)" columns motivate Task 6).

## Global Constraints

- **Framework rule (ROADMAP, owner 2026-10-26):** the framework holds abstractions, hooks and generic mechanisms only. Nothing here names holz, review mails, companies or upload failures.
- **Scope:** `packages/folio` and `packages/i18n` only. Never touch `packages/members`, `packages/auth`, `packages/data` or `packages/folio/src/Members` (the members split owns them).
- **Surfaces as the contract names them.** Where this plan refines one, it says so under "Rulings".
- **Texts:**
  - Every new key exists in `en`, `de` and `fr` in the same commit.
  - Admin German uses "du" and standard spelling (ß).
  - New keys go in the `folio` group.
- **Overrides stay plain text** (`StoredOverrides::validText()` unchanged). Admin groups keep their admin-only behaviour; public pages stay byte-identical for every group not listed as editable.
- **Branch:** `feat/site-admin-gaps` in `~/Sites/gbits/preflow-framework-site-admin`, based on `6a7f263` (members split, Task 3). One commit per task.
  - Never work in `~/Sites/gbits/preflow-framework` (another session).
  - No version bump, no composer constraint change, no tag, no merge or push: the release happens jointly after the members split.
  - Never touch holz-objekte.org.
- **Gates:** `vendor/bin/phpunit` baseline at `6a7f263`: 3634 tests, 99 PHPUnit deprecations, 90 skipped; add no deprecations.

## Rulings (for the owner to check)

1. **Editable groups (A1):**
   - `config/folio.php` `translations.editable` may list any group except `site` and except an `admin`-scope group. Those are skipped with one `error_log` line each (boot never breaks).
   - A `public` package group (e.g. `members`) may be listed: its overrides then reach public pages too.
   - Overrides of an editable group must keep exactly the `:placeholder`s of the delivered text (the locale's, else English). A save that drops one (e.g. `:link` in a mail) is refused with `folio.translations.placeholder_missing`; one that adds an unknown one with `folio.translations.placeholder_unknown`. Admin groups keep today's plain-text check only.
   - Placeholders are `/:([A-Za-z_][A-Za-z0-9_]*)/`, as `Translator::replaceParams()` fills them.
2. **The list is applied where Folio sets up the override layer** (`FolioServiceProvider::useOverrides()`, called from both `translator()` and `boot()`), not only in `boot()` as the draft said: a controller resolved before boot sees the same translator.
3. **Section labels and extension attribute labels (A3) already resolve** `site.sections.{section}` (`DisplayLabels::section()`) and `site.*` keys (`Customizations::texts()` → `FolioText::label()`). Task 4 pins both; the code change is the screen label only.
4. **Panel null (Task 5):** only `null` hides a panel. An empty string still renders an empty panel, as today.
5. **Link cell (Task 6):**
   - Named `Preflow\Folio\Extension\Link`, beside `Badge` (same namespace, same shape: a final value class a `#[ListColumn]` returns). `ListLink` was the alternative.
   - A URL without a leading `/` is relative to the admin prefix (`member/3/edit` → `{prefix}/member/3/edit`); a URL with one leading `/` is a site path, used as is. Anything with a scheme or host, `//`, a backslash, or a control or whitespace character is not a link: the label shows as plain text (lenient like `Badge`'s unknown tone, so a bad URL never breaks the list).
   - Extension columns are neither exported (`ListExport` writes only the type's own columns) nor sortable today. That stays: Task 6 pins that an export of a type with a link column still works and has no such column. Exporting extension columns would be its own change.

## Review Focus

- **A mail sent during an admin request in the member's language:** a German staff member approves, the mail goes out in French, and the French override of the editable group is used, not the German one and not the file text (Task 1 unit, Task 2 app).
- **Fallback with a partial override set:** an Italian visitor, a German override, a site `lang/it/review-mail.php` text: the Italian file text wins; the German override is reached only when Italian has no text at all (Task 1).
- **Misconfigured `editable`** (`['folio']`, `['site']`, `['../x']`): boot works, none is offered as a site group, Folio's overrides still never reach a public page (Task 2).
- **A screen badge method that throws, returns `'3'` or `0`, or belongs to a screen the user may not see:** no badge, the admin still renders, the hidden screen's method is never called (Task 3).
- **A link cell with `javascript:alert(1)`, `https://evil.example`, `//evil.example`, `/\evil.example` or `java\tscript:x`:** plain escaped label, no `href` (Task 6).

---

### Task 1: i18n: groups whose overrides apply on every request

**Files:**
- Modify: `packages/i18n/src/Translator.php`: new `alwaysOverride()` and `alwaysOverridden()`; `isOverridable()` and the private `valueIn()` changed; class docblock's precedence sentence.
- Modify: `packages/i18n/README.md`, "Admin overrides": a paragraph on always-overridden groups (Folio fills them from `config/folio.php`).
- Create: `docs/superpowers/specs/2026-10-26-site-admin-gaps-contract.md` (the contract copy, with A4 marked "deferred to after the members split" and A5/A6 appended from Tasks 5 and 6).
- Test: `packages/i18n/tests/TranslatorLayersTest.php`.

**Interfaces — Produces:**
- `Translator::alwaysOverride(string $group): void`: the group's overrides apply whether `useOverrides()` is on or off. Throws `\InvalidArgumentException` for `''` and for a group whose scope is `admin`. Idempotent.
- `Translator::alwaysOverridden(): list<string>`, sorted.
- `Translator::isOverridable(string $key): bool`: also true for a key of an always-overridden group.
- `valueIn()` consults the override layer when `($this->overridesOn && isOverridable($key))` or the key's group is always overridden; otherwise it never calls `TranslationOverrides::get()`.

- [ ] **Step 1: Write the failing tests** in `TranslatorLayersTest`:
  - `test_an_always_overridden_group_takes_overrides_with_overrides_off`: site file `lang/fr/review-mail.php` `['approved' => ['subject' => 'En ligne']]`, overrides `{fr: {review-mail.approved.subject: 'Votre objet est en ligne'}}`. After `alwaysOverride('review-mail')` and `useOverrides(false)`, `get('review-mail.approved.subject', [], 'fr') === 'Votre objet est en ligne'`; without the call, `'En ligne'`.
  - `test_an_always_overridden_group_uses_the_asked_locale_not_the_current_one`: current locale `de`, overrides for `de` and `fr`; `get(key, [], 'fr')` returns the `fr` override.
  - `test_a_locale_file_wins_over_a_fallback_locale_override`: fallback `de`, a `de` override, a site `it` file text; `get(key, [], 'it')` returns the `it` text. With the `it` file removed, the `de` override.
  - `test_parameters_in_an_always_overridden_text_are_replaced`: override `'Hallo :name'`, `get(key, ['name' => 'Ada'])` → `'Hallo Ada'`.
  - `test_an_admin_group_cannot_be_always_overridden`: `setScopes(['folio' => 'admin'])`; `alwaysOverride('folio')` and `alwaysOverride('')` throw `\InvalidArgumentException`; an admin key's override still needs `useOverrides(true)`.
  - `test_is_overridable_and_the_list`: after `alwaysOverride('review-mail')` twice, `isOverridable('review-mail.x')` is true, `alwaysOverridden() === ['review-mail']`, an unlisted public group's key stays false.
  - `test_other_keys_never_ask_the_override_layer`: a counting `TranslationOverrides` stub; with overrides off and `review-mail` always overridden, `get('members.x')` makes 0 calls, `get('review-mail.x')` makes 1.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/i18n/tests/TranslatorLayersTest.php`. Expected: FAIL, "Call to undefined method …alwaysOverride".
- [ ] **Step 3: Implement** the interfaces above, the docblock sentence, the README paragraph and the contract copy.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/i18n`. Expected: PASS, existing layer tests unchanged.
- [ ] **Step 5: Commit** `git add packages/i18n docs/superpowers/specs/2026-10-26-site-admin-gaps-contract.md && git commit -m "feat(i18n): groups whose overrides apply on every request"`

### Task 2: Folio: staff-editable site texts on the Translations screen

**Files:**
- Modify: `packages/folio/src/FolioServiceProvider.php`:
  - `useOverrides(Translator $translator, Container $c)` (private static, ~line 743) also applies `translations.editable` (Ruling 2). It reads the list through a new private static `editableGroups(Application $app): list<string>` from `config/folio.php` (the file `folioConfig()` reads; `useOverrides` is static, so read it via `$c->get(Application::class)`).
  - The `TranslationsController` binding (~line 277): pass the content locales (`$locales ?? \Preflow\Data\Locales::single('en')`, as the other bindings do) into `EditableTexts`.
- Modify: `packages/folio/src/Translations/EditableTexts.php`.
- Modify: `packages/folio/src/Http/TranslationsController.php`: `index()` (locale choice and the `locales` view value) and `store()` (locale check and placeholder check).
- Modify: `packages/folio/templates/admin/translations/index.twig`: the language select from the controller's per-group list; the note for an editable group.
- Modify: `packages/folio/lang/{en,de,fr}/folio.php`, group `translations`: `site_texts`, `placeholder_missing`, `placeholder_unknown`.
- Modify: `packages/folio/README.md`, "Admin languages → Changing a text": an "Editable site texts" paragraph; the "Overrides apply on admin requests only" bullet gains "except editable site groups".
- Test: `packages/folio/tests/Integration/TranslationOverridesTest.php`.

**Interfaces:**
- Consumes: Task 1 (`alwaysOverride`, `alwaysOverridden`, `isOverridable`).
- Produces:
  - Config `config/folio.php` `'translations' => ['editable' => list<string>]`. Each entry must match `/^[a-z0-9_-]+$/i`, must not be `site`, must not be an `admin`-scope group; anything else is skipped with `error_log('folio: translations.editable: …')` naming the entry.
  - `EditableTexts::__construct(Translator $translator, ?DerivedKeys $derivedKeys = null, ?\Preflow\Data\Locales $siteLocales = null)`.
  - `EditableTexts::isEditableGroup(string $group): bool`: the group is in `$translator->alwaysOverridden()`.
  - `EditableTexts::locales(string $group, list<string> $interface): list<string>`: `$siteLocales->available` for an editable group, else `$interface`.
  - `EditableTexts::placeholders(string $key, string $locale): list<string>`: the delivered text's placeholder names (Ruling 1), sorted.
  - `groups()`: admin groups sorted, then `site`, then the editable groups sorted.
  - `rows()` for an editable group: keys are the union over every site locale and `en`; `delivered` is the package text, else the site file text; `site` only when a package text exists and differs.
  - `editable($key, $locale)` also requires `$locale` in `locales($group, …)`.
- Copy:
  - `site_texts`: en "These texts appear on the website and in mails, in every language of the website." / de "Diese Texte erscheinen auf der Website und in Mails, in jeder Sprache der Website." / fr "Ces textes apparaissent sur le site et dans les e-mails, dans chaque langue du site."
  - `placeholder_missing`: en "Keep the placeholder :placeholder: it is filled in when the text is used." / de "Behalte den Platzhalter :placeholder: Er wird beim Verwenden des Textes ausgefüllt." / fr "Garde l’espace réservé :placeholder : il est rempli quand le texte est utilisé."
  - `placeholder_unknown`: en "The placeholder :placeholder is not filled in here." / de "Der Platzhalter :placeholder wird hier nicht ausgefüllt." / fr "L’espace réservé :placeholder n’est pas rempli ici."
  - (The message's own `:placeholder` param is the name with its colon, e.g. `:link`.)

- [ ] **Step 1: Write the failing tests** in `TranslationOverridesTest`. The site has `config/i18n.php` with `available ['de','fr','it']`, `lang/{de,fr,it}/review-mail.php` with `approved.subject` and `approved.body` ("… :link …"), and `config/folio.php` `['translations' => ['editable' => ['review-mail']]]`.
  - `test_an_editable_group_is_listed_in_every_site_language`: `/folio/translations?group=review-mail&locale=it` shows the `it` file text and the `site_texts` note; the select offers `de`, `fr`, `it`; `?group=folio` still offers only the interface languages.
  - `test_an_editable_override_must_keep_the_placeholders`: saving `approved.body` for `de` without `:link` → 422 whose body names `:link`, nothing stored; with `:link` kept → stored; with an extra `:foo` → 422 naming `:foo`.
  - `test_an_editable_override_reaches_a_public_page_and_a_mail`: after POST `/folio/translations` `{locale: fr, key: review-mail.approved.subject, text: 'Votre objet est en ligne'}`, a public page `/fr/hello` with `{{ t('review-mail.approved.subject') }}` renders it; `Translator::get(key, [], 'fr')` during an admin request by a German user gives it too; a `folio.common.save` override set in the same test still doesn't reach `/hello`.
  - `test_an_editable_override_is_plain_text_like_any_other`: `<b>x</b>` → 422, nothing stored.
  - `test_an_admin_group_or_site_listed_as_editable_is_skipped`: `editable: ['folio', 'site', '../x', 'review-mail']` → boot succeeds, `alwaysOverridden() === ['review-mail']`, the existing `test_public_pages_never_see_an_override` assertions hold.
  - `test_a_locale_outside_the_group_is_refused`: `{locale: it, key: folio.common.save}` → 422; `{locale: es, key: review-mail.approved.subject}` → 422.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Integration/TranslationOverridesTest.php`. Expected: the new tests FAIL (group not listed, locale refused).
- [ ] **Step 3: Implement.** The controller computes `$offered = $this->texts->locales($group, $this->locales->all())` and uses it for the select (names from `LocaleNames::native()`) and for validation in place of `InterfaceLocales::offers()`. `store()` runs the placeholder check only for an editable group, after `validText()`.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio packages/i18n`. Expected: PASS, including `AllScreensTranslatedTest` and `TranslationsDeclarationTest`.
- [ ] **Step 5: Commit** `git add packages/folio && git commit -m "feat(folio): staff edit a site's own text groups on the Translations screen"`

### Task 3: Folio: `#[NavBadge]` on a screen

**Files:**
- Modify: `packages/folio/src/Extension/Screen/ScreenRegistry.php`: private static `screen()` (~line 160) finds the badge method; `all()`/`get()` entries gain `'badge' => ?string` (the method name).
- Modify: `packages/folio/src/Http/AdminNav.php`: `add()` takes a badge closure; `sections()` (~line 113) uses it for added items.
- Modify: `packages/folio/src/FolioServiceProvider.php`, the screen loop in `boot()` (~line 825): pass the badge closure.
- Modify: `packages/folio/src/Extension/NavBadge.php`: docblock (type or screen).
- Modify: `packages/folio/README.md`: the `#[NavBadge]` row of the Type customizations table, and "Screens".
- Test: `packages/folio/tests/Extension/NavBadgeTest.php` (its `crm()` helper writes the extension; add a screen class).

**Interfaces — Produces:**
- `AdminNav::add(string $section, string|Closure $label, string $url, string $icon, ?string $permission, int $order = 100, ?Closure $badge = null): void`. `$badge` is `Closure(): mixed`, called only for a visible item, at most once per `AdminNav` instance (memoized like `Customizations::navBadge()`). A non-int, a value ≤ 0 or a throw shows no badge.
- The screen's badge method: public, non-static, `#[NavBadge]`, no required parameter, one per class.
  - Static or non-public: `ExtensionException("{$class}::{$method} must be a public, non-static method to carry #[NavBadge].")`.
  - Two: `ExtensionException("{$class} has more than one #[NavBadge]: {$first} and {$second}.")` (the type wording).
- The provider passes `static fn (): mixed => $container->get($screen['class'])->{$screen['badge']}()`.

- [ ] **Step 1: Write the failing tests** in `NavBadgeTest`. The crm extension gets `Screens/Queue.php`: `#[FolioScreen('queue', 'Queue', section: 'content', permission: 'crm.queue')]` with `#[NavBadge] public function waiting(): mixed`, its value from the test.
  - `test_badge_count_shown_on_screen_item`: count 4 → `'<span>Queue</span><span class="folio-nav-badge" aria-label="4 waiting">4</span>'`.
  - `test_screen_badge_zero_null_non_int_or_throwing_shows_none` (data provider: `0`, `null`, `'3'`, a throw): no `folio-nav-badge"`, dashboard 200.
  - `test_screen_badge_not_called_for_a_user_without_the_permission`: the editor lacks `crm.queue`; the method writes a marker file when called; absent after the editor's dashboard renders.
  - `test_static_or_second_screen_badge_throws_at_boot`: each case throws `ExtensionException` with the messages above.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Extension/NavBadgeTest.php`. Expected: the new tests FAIL (no badge markup).
- [ ] **Step 3: Implement** the interfaces above. In `sections()`, `'badge'` is the type badge for type items and the normalized closure result for added items.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio/tests/Extension packages/folio/tests/Http/AdminNavTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git add packages/folio && git commit -m "feat(folio): #[NavBadge] on a screen shows a sidebar count"`

### Task 4: Folio: screen labels through keys

**Files:**
- Modify: `packages/folio/src/FolioServiceProvider.php`, the screen loop in `boot()`: label `static fn (): string => \Preflow\Folio\FolioText::label($screen['label'])`.
- Modify: `packages/folio/src/Extension/Screen/ScreenDispatcher.php` (~line 60): `'screen' => ['label' => FolioText::label($screen['label']), 'path' => …]`.
- Modify: `packages/folio/src/Extension/Attributes/FolioScreen.php`: docblock (`label` may be a key, e.g. `site.screens.queue`).
- Modify: `packages/folio/README.md`, "Screens" and "Admin languages": keys `site.screens.{name}` (new table row) and `site.sections.{section}`; a project extension's attribute labels as `site.*` keys.
- Test: create `packages/folio/tests/Extension/ScreenLabelsTest.php`, in `NavBadgeTest`'s setup style.

**Interfaces:** Consumes `FolioText::label(string): string` and `DisplayLabels::section()`, both unchanged. Produces nothing new: the screen label resolves per request, in the user's interface language.

- [ ] **Step 1: Write the failing tests.** The site has `lang/{en,de,fr}/site.php` and an extension in `app/Folio/Crm` (no `translations:` group): a screen `#[FolioScreen('queue', 'site.screens.queue', section: 'review', permission: 'crm.queue')]` whose template prints `<h1>{{ screen.label }}</h1>`; a customization of a type with `#[ListColumn('site.columns.kind')]`, `#[FormPanel('site.panels.check', position: 'side')]` and `#[RecordAction(name: 'go', label: 'site.actions.go', permission: 'crm.go')]`. The admin's preference is `de`.
  - `test_a_screen_label_key_follows_the_interface_language`: `de` → `<span>Prüfen</span>` in the sidebar and `<h1>Prüfen</h1>`; preference `fr` → both `À vérifier`.
  - `test_a_plain_screen_label_stays_as_written`: `'Queue'` shows as `Queue` in German.
  - `test_a_screen_only_section_takes_site_sections_key` (pin, Ruling 3): the `review` heading shows `Prüfung`.
  - `test_project_extension_attribute_labels_take_site_keys` (pin): German list header, panel title and action button show the `site.php` texts.
  - `test_a_key_shaped_label_without_a_text_shows_the_key`: `site.screens.missing` shows as written.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Extension/ScreenLabelsTest.php`. Expected: the first test FAILS (raw key shows); the pins may already pass.
- [ ] **Step 3: Implement** the two call-site changes.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio/tests/Extension`. Expected: PASS, `ScreenTest` unchanged.
- [ ] **Step 5: Commit** `git add packages/folio && git commit -m "feat(folio): screen labels resolve translation keys"`

### Task 5: Folio: a `#[FormPanel]` returning null is hidden

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`, private `recordExtras()` (~line 1064): a panel whose callable returns `null` adds no entry to `$panels` (and no assets). This is the only place panels are built; `templates/admin/form.twig` (lines 12, 104–106, 110–113, 152) already omits the side column and the extras section when the filtered lists are empty, so it needs no change.
- Modify: `packages/folio/src/Extension/FormPanel.php`: docblock (`fn(DynamicRecord): ScreenView|string|null`; null hides it).
- Modify: `packages/folio/README.md`: the `#[FormPanel]` row of the Type customizations table.
- Test: `packages/folio/tests/Extension/AdminPolishTest.php` (its `polish()` extension already has a side panel "Decision" and an after panel "Plain after").

**Interfaces — Produces:** a `#[FormPanel]` method may return `null`: no heading, no box, no side column or extras section on its account (Ruling 4).

- [ ] **Step 1: Write the failing tests** in `AdminPolishTest`. Add to the `polish()` customization a side panel `#[FormPanel('Maybe', position: 'side')]` and an after panel `#[FormPanel('Maybe after')]`, both returning `null` when the record's `name` is `'Hidden'`, else `'shown'`.
  - `test_a_panel_returning_null_renders_nothing`: record `name: 'Hidden'` → edit page has no `Maybe` and no `Maybe after`; the existing "Decision" and "Plain after" panels still render.
  - `test_only_null_panels_leave_no_side_column`: `polish()` gains a flag under which every panel of the customization (Decision, Plain after, Maybe, Maybe after) returns null → no `has-side`, no `<aside class="folio-side"`, and no `folio-form-extras` (the fixture type doesn't publish).
  - `test_a_panel_returning_a_string_renders_as_before`: record `name: 'Ada'` → `<h2 class="folio-panel-title">Maybe</h2>` and the body `shown`; `test_side_panel_renders_in_side_slot` stays green.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Extension/AdminPolishTest.php`. Expected: FAIL (an empty panel box with its heading renders).
- [ ] **Step 3: Implement** the `recordExtras()` change.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio/tests/Extension`. Expected: PASS.
- [ ] **Step 5: Commit** `git add packages/folio && git commit -m "feat(folio): a form panel returning null is hidden"`

### Task 6: Folio: a `#[ListColumn]` cell may be a link

**Files:**
- Create: `packages/folio/src/Extension/Link.php`.
- Modify: `packages/folio/src/Http/AdminController.php`, the list's `'extra'` cells (~line 159): a `Link` becomes `['label' => …, 'href' => $link->href($this->prefix)]` when `href()` is non-null, else its plain label string.
- Modify: `packages/folio/templates/admin/_list_results.twig` (line 71): a cell with `href` renders `<a href="{{ cell.href }}">{{ cell.label }}</a>` (auto-escaped); a badge cell (`{label, tone}`) keeps its exact markup (site overrides of the list template read that shape).
- Modify: `packages/folio/src/Extension/ListColumn.php`: docblock (`string|Badge|Link`).
- Modify: `packages/folio/README.md`: the `#[ListColumn]` table row, and the `row.extra` paragraph under "`list`: the admin list" (a cell may be `{label, href}`); note that extension columns are not in the Excel export and not sortable (Ruling 5).
- Test: `packages/folio/tests/Extension/AdminPolishTest.php` (list cells) and create `packages/folio/tests/Extension/LinkTest.php` (the URL rule, unit level).

**Interfaces — Produces:**
```php
namespace Preflow\Folio\Extension;
final class Link
{
    public function __construct(public readonly string $label, public readonly string $url) {}
    /** The href, or null when the URL is not an admin-relative or same-site path (Ruling 5). */
    public function href(string $adminPrefix): ?string;
}
```
- `href('/folio')`: `'member/3/edit'` → `'/folio/member/3/edit'`; `'/shop/order?id=3'` → `'/shop/order?id=3'`. A URL must start with `/` or `[A-Za-z0-9_]`.
- null for: `''`, `'?state=open'`, `'#x'`, a scheme (`javascript:alert(1)`, `JavaScript:x`, `https://evil.example`, `mailto:a@b.c`, `data:text/html,x`), `//evil.example`, `/\evil.example`, any `\`, any ASCII control or whitespace character (`java\tscript:x`, `/a b`). A `:` before the first `/`, `?` or `#` counts as a scheme.

- [ ] **Step 1: Write the failing tests.**
  - `LinkTest::test_admin_relative_and_site_paths_are_links` and `test_anything_else_is_no_link` (data provider over the values above).
  - `AdminPolishTest::test_list_column_link_renders_escaped_anchor`: a column `#[ListColumn('Owner', position: 50)]` returning `new Link('Ada <b>', 'member/m1/edit')` → `<td><a href="/folio/member/m1/edit">Ada &lt;b&gt;</a></td>`.
  - `AdminPolishTest::test_list_column_link_with_unsafe_url_is_plain_text`: `new Link('Evil', 'javascript:alert(1)')` → `<td>Evil</td>`, and the page has no `javascript:`.
  - `AdminPolishTest::test_export_with_a_link_column_still_works` (skip without OpenSpout, as `ListExportTest` does): `GET /folio/member/_export` → 200, the header row has the type's columns and no `Owner`.
  - `test_list_column_badge_renders_with_tone_and_escapes` stays green, unchanged.
- [ ] **Step 2: Run** `vendor/bin/phpunit packages/folio/tests/Extension/LinkTest.php packages/folio/tests/Extension/AdminPolishTest.php`. Expected: FAIL ("Class …Link not found").
- [ ] **Step 3: Implement** `Link`, the controller cell and the template branch.
- [ ] **Step 4: Run** `vendor/bin/phpunit packages/folio/tests/Extension packages/folio/tests/Content/ListExportTest.php`. Expected: PASS.
- [ ] **Step 5: Commit** `git add packages/folio && git commit -m "feat(folio): a list column cell may be a link"`

### Task 7: Merge-ready

**Files:**
- Modify: `packages/folio/README.md`, `packages/i18n/README.md`: read through the sections Tasks 1–6 touched; every new surface is documented once (config `translations.editable` with the placeholder rule; `#[NavBadge]` on screens; `site.screens.{name}`; panel null; `Link`).
- Modify: `ROADMAP.md`, `## Completed`: a `### v0.38.0 (prepared)` heading above `### v0.37.0 (prepared)` if absent, with these bullets in the 0.37 entry's style (bold lead, one line each). The members split adds its own bullets under the same heading; on a conflict keep both sets.
  - **Editable site texts:** `config/folio.php` `translations.editable`; overrides of listed groups apply on every request (public pages, mails, console) in every content language; placeholders kept; `site` and admin groups skipped. i18n: `Translator::alwaysOverride()`.
  - **Screens:** `#[NavBadge]` on a `#[FolioScreen]`; screen labels may be keys (`site.screens.{name}`); `AdminNav::add()` takes an optional `$badge`.
  - **Type customizations:** a `#[FormPanel]` returning null is hidden; `#[ListColumn]` may return `Link($label, $url)` (admin-relative or same-site paths only).
  - **For the joint release:** Folio needs i18n `^0.38` (conflict `<0.38`) for `alwaysOverride()`; the members split's release task bumps the constraints and `ComposerConstraintsTest`.
- Modify: `ROADMAP.md`, "Follow-ups from the holz-objekte.org port → Members": one bullet listing the deferred member surfaces (see "Deferred" below).
- No version bump, no constraint change, no release checklist (the members split's Task 8 creates `release-0.38-checklist.md`), no tag.

- [ ] **Step 1: Check translations:** every key added in Tasks 2–6 exists in `packages/folio/lang/{en,de,fr}/folio.php`; German uses "du". Run `vendor/bin/phpunit packages/folio/tests/TranslationsDeclarationTest.php packages/folio/tests/Integration/AllScreensTranslatedTest.php packages/i18n/tests/Console`. Expected: PASS.
- [ ] **Step 2: Docs and ROADMAP** as listed above.
- [ ] **Step 3: Run** `vendor/bin/phpunit packages/folio packages/i18n`. Expected: PASS, no new deprecations. Then `vendor/bin/phpunit` and compare with the baseline (3634 + the new tests, 99 deprecations).
- [ ] **Step 4: Commit** `git add ROADMAP.md packages/folio/README.md packages/i18n/README.md && git commit -m "docs: folio site admin gaps, merge-ready for 0.38"`

---

## Deferred to after the members split

Not in this plan: the members package is being split (`docs/superpowers/specs/2026-10-26-members-split-design.md`), and these build on its result. Contract A4 surfaces, to pick up then:
- `Members::lock(DynamicRecord)`, `unlock(DynamicRecord)`, `isLocked(DynamicRecord): bool` with a readonly `locked_at` field (`''` = not locked); a lock covers every sign-in path (form refusal as for a declined member, no reset mail, reset link 410, confirmation never signs in) and never makes a member look "declined" (a "Gesperrt" pill, tone `incomplete`).
- `Members::endSessions(DynamicRecord)`: `auth_epoch` + 1 and `RememberMe::revokeAll()`.
- `Members::resendConfirmation(DynamicRecord)`: fresh link to the current address through `MemberMailer`, `\LogicException` when confirmed; the public resend shares the code path.
- Last sign-in: a readonly `last_login_at`, set on form sign-in and after reset and confirmation.

Where they land after the split (from its plan): the lock check in the core's sign-in (`SignIn::in()`, split Task 5); `last_login_at` as a listener on `MemberEvents::SIGNED_IN` (split Task 6); the locked pill in `packages/folio/src/Members/MemberAdmin.php` (moved there by split Task 3, not `packages/members/src/Types/` as the draft had).
