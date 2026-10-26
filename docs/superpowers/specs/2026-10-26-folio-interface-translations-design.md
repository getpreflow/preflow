# Folio Interface Translations — Design

**Date:** 2026-10-26
**Status:** Draft for the owner's review (builder check done, owner's answers in).
**Context:** A client project needs Folio's admin in German and French, as soon as possible. It is
also the base for every package still to come (members split, codes, reports): new packages ship
translated from day one, so nothing is touched twice (owner).

## Why

- A client project's editors speak German and French. Today Folio's admin is English: templates
  and controllers write their text directly. Only about 15 strings exist in
  `packages/folio/lang/{en,de,fr}/folio.php` (the staff mails).
- Every package with an admin screen (Folio, media, cache, members, codes, rbac, a site's own
  extensions) must bring its own texts, found automatically through a global registry, like
  commands are today through `extra.preflow` (owner).
- Wording that doesn't fit a client can be fixed without touching a package (owner: "as
  delivered by the product", with overrides).

**Standing rule (owner):** the capability lives in the core (`preflow/i18n`) behind public
interfaces. Folio only uses it and adds UI.

## Goals, non-goals, anti-goals

**Goals:**
- Every text a user sees in the admin follows that user's language: navigation, labels,
  buttons, notices, errors, empty states, confirm dialogs, sign-in, invite and password pages,
  staff mails, validation errors, the screens of other packages, and admin JavaScript texts.
- Site texts translate too: type names, navigation entries, field labels, help texts and select
  options from the site's model files (owner).
- English, German and French are delivered with the framework, written by us (owner).
- More languages are added as files, without code changes (owner).
- Dates and numbers appear in the user's language format.

**Non-goals:**
- Right-to-left languages (owner: not for now).
- Translating content: a site's content languages and frontend translation already exist and
  are a separate matter. An editor's interface language is independent of the content
  language being edited.
- Machine translation inside Folio.

**Anti-goals:**
- A raw key (`folio.assets.save`) or a blank where text belongs.
- A package that needs Folio code changes to bring its texts.
- Overrides that are lost on a package update.
- A slower admin: translation lookups must not add measurable time per request.

## Decisions (the owner's)

- **Each user picks their own interface language** (owner). A German and a French editor work
  side by side in the same admin.
- **Delivered languages:** English, German, French. We write them (owner).
- **German uses "du" in the admin only** (owner): the admin interface and staff mails. Public
  texts (members' registration, account and mails to members) keep their "Sie".
- **Standard German spelling** ("Grüße", with ß) (owner). Who wants Swiss spelling overrides
  single strings. The existing Swiss-spelled Folio staff mails switch to standard German.
- **Overrides are for the admin interface only** (owner). Public texts (a site's frontend, the
  members pages) are not edited through Folio; a later, separate solution built on the
  translation package covers them.
- **Overrides by developers and admins** (owner): a developer in the site's language files, an
  admin in a Folio screen.
- **Site labels and navigation are translatable** (owner).
- **All at once**, as soon as possible (owner).

## Design

### 1. Discovery: packages bring their texts (core, `preflow/i18n`)

- A package declares its language files in its `composer.json`:

  ```json
  "extra": { "preflow": { "translations": {
      "media": { "path": "lang", "scope": "admin" },
      "members": { "path": "lang", "scope": "public" }
  } } }
  ```

  The key is the translation group (`media`, so keys read `media.assets.replace`); `path` is
  the folder relative to the package root, holding `{locale}/{group}.php`; `scope` says whether
  the group is admin interface text (`admin`) or public text (`public`). A package with both
  splits them into two groups (members: `members` stays public, its admin texts go to
  `members-admin`). A plain string value (`"media": "lang"`) means `scope: admin`.
- At boot, i18n reads the installed packages' declarations (from Composer's installed metadata,
  the same source the commands registry uses) and registers each with
  `Translator::addDefaults()`. The calls that Folio, members and rbac make by hand today are
  replaced by the declaration.
- A site's own extensions declare theirs the same way, or call `addDefaults()` from their
  provider.
- `preflow translations:check` (a command) lists, per package and language, keys that exist in
  English but are missing in another delivered language, and keys used in templates that exist
  nowhere. It is the release gate for "no raw keys".

### 2. Layers and fallback (core)

For a key in the user's language, the first that has it wins:

1. an admin's override (Folio, section 5);
2. the site's `lang/{locale}/{group}.php`, key by key (exists today);
3. the package's delivered file.

When none has it in the user's language: the site's default language, then English. A key
missing everywhere renders as the English source text if the call gave one (see 4), else the
key itself. That last case is caught by `translations:check` before a release.

The override layer is a core interface, `TranslationOverrides` (`get(locale, key): ?string`, a
whole-locale map for caching), with an empty default. Folio provides the stored implementation.
It is consulted only for groups of `scope: admin` and only on admin requests, so public pages
render exactly as today and pay nothing for it.

### 3. The user's interface language (core + Folio)

- The core gets an `InterfaceLocale` service: the locale for this admin request. Folio sets it
  from the signed-in user's preference, else the site's default admin language
  (`folio.locale`, default: `app.locale`), else English.
- It works for both kinds of users: record users (smart-stack) and config users (smeyer.me). The
  preference is stored by Folio per user id in its own small store, so config users need no
  record.
- The choice is in the user's own settings and as a language switch in the sidebar footer, next
  to the theme switch. Only languages with delivered or site texts are offered.
- The admin translator runs in the interface locale. The frontend keeps its own locale
  (`LocaleMiddleware`), so a page preview in the admin still shows content in the content
  language.
- Staff mails go out in the recipient's interface language (`Translator::get(…, $locale)`
  exists).

### 4. Folio's own texts

- Every user-visible string in `packages/folio/templates` and `packages/folio/src` moves to
  `packages/folio/lang/{en,de,fr}/folio.php` under readable keys (`folio.assets.title`,
  `folio.common.save`). Templates use `t()`; controllers use the translator.
- JavaScript texts (confirm dialogs, toasts, the unsaved-changes guard, the image editor) are
  handed to `admin.js` as one JSON dictionary for the current locale, in a nonce'd `<script
  type="application/json">` block (CSP-safe). There are no strings left in the JS files.
- Validation messages come from the validation package's own translated group.
- Dates and numbers use `IntlDateFormatter` / `NumberFormatter` when `intl` is present, else a
  simple per-language pattern from the language file.

### 5. Admin overrides (Folio)

- A screen under Settings, **Translations**, behind a new permission `translations.edit`:
  - a search over keys and texts, filtered by package (group) and language;
  - per key: the delivered text, the site file's text (if any), and an override field;
  - "Reset" removes the override.
- The screen lists only `scope: admin` groups (plus the `site` group's labels, section 6).
- Overrides are stored as records of a Folio-owned type (key, locale, text, who, when), so they
  travel with Folio's export and import and survive package updates.
- They are compiled per locale into a file under `storage/cache`, registered as a source of
  Folio's existing cache registry (Settings → Cache), and cleared when an override is saved
  (my call; no new cache package).
- Overrides are plain text: a text containing `<` or `>` is refused on save. Keys whose
  delivered text contains markup (a consent text with a link) are not offered on the screen;
  they are changed in the site's language files (my call). So no escaping layer is needed in
  the core, and the same text works in HTML, the JS dictionary, plain-text mails and exports.

### 6. Site texts in model files (core `preflow/data` + Folio)

- Wherever a model file has a display text (type `label`, field `label`, `help`, select option
  labels, list column titles, navigation section names), it may be a string, as today, or a
  map per language:

  ```json
  "label": { "en": "Apps", "de": "Apps", "fr": "Applications" }
  ```

- A string keeps working. It counts as the site's default language, and it is also the
  English fallback when no `en` entry exists.
- These texts also get keys in a `site` group (`site.types.app.label`,
  `site.fields.app.name.label`), so a developer can translate them in `lang/{locale}/site.php`
  and an admin can override them on the Translations screen. Order: admin override, site
  language file, the map in the model file, the plain string.
- Model files that packages and extensions ship (codes' `code.json`, members' models, a Folio
  extension's models) get keys in the owning package's group
  (`codes.types.code.fields.purpose.label`), delivered in en, de and fr from that package's
  `lang/` (my call). `translations:check` also reads model files for used keys.
- Folio shows the resolved text everywhere it shows these labels today (navigation, lists,
  forms, filters, bulk actions, exports' header row).

### 7. Packages already shipping admin screens

media (library, editor), Folio's cache screen, members, codes and rbac move their admin texts
into their own `lang/` folders with en, de and fr, and declare them (section 1). Their admin
templates use `t()` with their group. Members' public texts stay in `members` (`scope: public`,
"Sie", unchanged); its admin texts move to `members-admin`.

## Delivered translations

- We write en, de and fr for every key: about 600–900 strings across the packages, mostly
  labels and short messages.
- German uses "du" and standard spelling. French: neutral wording where possible ("Enregistrer", "Supprimer") and
  "vous" where a sentence needs a pronoun, which is usual in French software (my call).
- Terms stay consistent across packages: a short glossary (`docs/translations-glossary.md`)
  fixes the words for asset, record, page, publish, draft, version and so on, in each
  language.

## Constraints

- PHP 8.4, the existing i18n `Translator` (`get`, `choice`, `addDefaults`), Twig `t()`.
- Strict nonce CSP: no inline scripts or styles; the JS dictionary goes in a JSON block.
- No measurable slowdown: groups are loaded once per request; overrides once per locale from
  the cache.
- Byte-identical frontend: public pages don't change (overrides never apply to them).
- Locales are two-letter codes (`de`, `fr`), as `app.locale` and `LocaleMiddleware` use today.
  Regional variants (Swiss spelling) are overrides, not locales (my call, following the owner's
  answer).

## Testing

- `translations:check` passes for en, de and fr in every package.
- A test renders every Folio admin screen in German and French and fails on any raw key or any
  leftover English sentence from a list of known source strings.
- Precedence: an admin override beats the site file, which beats the package file. Missing in
  French falls back to the site default, then English.
- Two users in one test: one German, one French; each sees their own language on the same
  screen.
- Model labels: a plain string, a per-language map, a site-file key and an admin override each
  win in the documented order.
- Mails go out in the recipient's language.
- An override containing `<` or `>` is refused; markup keys are not on the screen.
- A public page (a members page, a site frontend text) renders byte-identically with and
  without admin overrides present.

## Left to the builder

- The exact key names and file organisation inside each group.
- How the discovery reads Composer's installed metadata (reuse the commands registry's reader).
- The store for user preferences and the override record type's name.
- The layout of the Translations screen, within Folio's existing list/edit patterns.

## Size

Large: one design, one plan of about 7–8 tasks (core discovery and layers; interface locale and
user choice; Folio extraction, which is the biggest; JS texts and formats; overrides screen;
model labels; other packages; translations and release). One release, 0.37.
