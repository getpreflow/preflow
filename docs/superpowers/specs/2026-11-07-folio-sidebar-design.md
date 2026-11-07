# Folio sidebar: collapsible sections (and a quick jump later)

Owner, 2026-11-07: "The side navigation in folio can get quite long depending on how much content types are there
or modules are installed … the most obvious seem to be expandable sections." Scope agreed in chat: this belongs to
Folio alone (admin UI), not to the core packages.

## Why

The sidebar lists every section with all of its items:
- Dashboard, Pages and Content;
- each section a model file names (`"section"` in a model JSON);
- each section an extension adds through `AdminNav::add()`;
- Library and Settings.

On a site with many types or extensions (holz-objekte.org, BG Genius) it grows past the screen. People then scroll to
reach Settings, and an item that needs attention (a badge) can sit out of view.

## Goals

1. **Collapsible sections.**
   - Every section with a heading can be closed and opened by its heading. Dashboard has no heading and stays as it is.
   - Closed means only the heading shows.
   - It works without JavaScript and with the keyboard.
2. **Remembered per user.** The open/closed state of each section is saved in the person's Folio preferences, next to
   the admin language they already have. It follows them to any browser and device, and every page of the admin
   renders it from the server, so nothing jumps after the page loads.
3. **The current screen is never hidden.** The section that contains the current screen always renders open, whatever
   was saved. Its saved state does not change.
4. **Badges stay visible.** A closed section shows the sum of its items' badges next to its heading, with the same look
   and the same "waiting" label text as an item badge. An open section shows the item badges as today.
5. **Site defaults.**
   - `config/folio.php` gets `'nav' => ['collapsed' => [...section names...]]`: the sections that start closed for
     someone who has never opened or closed them. The names are the same section names model files and extensions use,
     plus the built-in `pages`, `content`, `library` and `settings`.
   - A person's own choice wins over the default, section by section.
   - *My call:* the shipped default is `['library', 'settings']`. They are used least, and Settings shows its badge
     anyway (goal 4).
6. **Mobile drawer.** The narrow-screen drawer uses the same sections and the same state.

## Later (separate design, not part of this)

- **Quick jump (⌘K / Ctrl+K):** a search box over every sidebar item the person may open, and optionally over records
  by title. It is a separate piece of work with its own design.
- **Not planned:** pinned favourites and an icon-only narrow sidebar. Each brings its own settings; goals 1–5 should
  cover most of the problem. Revisit if they don't.

## Non-goals / anti-goals

- No change to which items someone sees: permissions decide that, as today.
- No change to section order or item order. The `sectionOrder()` rules stay.
- Nothing in the core packages. No new interface for sites. Sites get this by updating Folio and may set
  `nav.collapsed`.
- Anti-goal: a click on a heading that reloads the page or loses unsaved form input in the main area.
- Anti-goal: a badge that disappears because its section is closed.
- Anti-goal: an admin page that flashes open and then closed (or the reverse) while it loads.

## How it works (decided)

### Markup
- **Element:** each section with a heading renders as a `<details class="folio-nav-section" data-section="{name}">`. Its
  `<summary>` holds the heading (`folio-nav-label`) and the summed badge, and the items follow inside.
- **State:** `open` is set from the server: the saved state, else the site default, else open. The section holding
  the current screen is always `open`.
- **Without JavaScript:** a closed section opens and closes in the browser, but the choice is not saved.

### Saving the state
- `admin.js` listens for `toggle` on `details.folio-nav-section` and sends the change to a new Folio endpoint, e.g.
  `POST {prefix}/_prefs/nav` with `section` and `open`.
- **The request:** it is a fetch with the CSRF token, so there is no page load. It needs a sign-in only, like the language
  switch, with no extra permission.
- **Failure:** a failed save is silent; the section stays as the person left it on this page.
- **What is stored:** `UserPreferences` gains `navCollapsed(string $authId): array<string, bool>` and
  `setNavSection(string $authId, string $section, bool $open): void`. It stores only sections the person changed, as
  `section => open`.
- **Unknown names:** a section name that no longer exists (an extension removed) is ignored when rendering. It may be
  dropped the next time that person saves.

### Badges
`AdminNav::sections()` adds a `badge` total per section: the sum of its visible items' badges, or null when there are
none. The template shows it in the `<summary>`, for closed sections only. Use CSS on `details:not([open])`, so it
shows the moment someone closes a section, without a page load.

### Texts
- No new visible wording besides an accessible name for the toggle if the heading alone doesn't serve, e.g. "Pages,
  section, collapsed". This is a judgement call for the builder after a screen-reader check.
- New texts go into Folio's translation files in de, en and fr.

## Constraints

- PHP 8.4+, current Folio (0.56.x). Folio's own CSS and JS (`assets/admin.css`, `assets/admin.js`).
- **CSP:** Folio's admin scripts already carry the nonce. Add no inline script.
- **Tests:** Folio's own test suite, plus one site suite after release (the owner's sites are upgraded with every
  release).
- **Release:** a Folio minor, with a ROADMAP entry. Sites only need `composer update`.

## Calls made after the builder check (mine)

1. **Language and nav state are separate.** `setLocale(null)` only clears the language. A person's preference row is
   deleted only when nothing is left in it, so choosing "browser default" never wipes their sections.
2. **One section per save.** The endpoint changes only the section it was sent; the last write wins per section. Two
   tabs or devices toggling different sections don't overwrite each other. No locking.
3. **Storage.** A new `nav` JSON field on the preferences type, added the way Folio adds columns today. Reading
   from an older table without the column counts as "nothing saved" and never fails. No `preflow migrate` step is
   required.
4. **The current screen is the active sidebar item.** Forcing a section open uses the item that `AdminNav` already
   marks active. A screen that matches no item (profile, a 404) forces nothing open.
5. **A click is a choice.** Closing a section that was forced open closes it and saves "closed". On the next page that
   isn't in that section, it stays closed.
6. **`sections()` gains keys, loses none.** It adds `name` and `badge` to each section; existing keys stay as they are.
7. **`nav.collapsed`** is read from Folio's config with `['library', 'settings']` as the shipped default. A missing
   value means none. A value that isn't a list of strings is ignored, with no error.
8. **One sidebar.** The mobile drawer is the same `<aside>`, styled for narrow screens. If the builder finds a second
   copy of the markup, both copies render from the same data, and a toggle updates both.
9. **Release routine.** Folio's tests, a Folio release, then the usual upgrade and deploy of all the owner's sites with
   their suites. The browser check by hand happens on one of them.

## Left to the builder


- The exact endpoint path and its controller placement inside Folio.
- CSS details: the open/closed marker, spacing and transition. It must match the visual refresh (0.48.4).
- Where the summed badge is computed (in `AdminNav` or the template), as long as it is tested.

## Verification

- Folio tests:
  - **Saved state:** a closed section renders without `open` for that person, and other people are unaffected.
  - **Current screen:** the section holding the current screen renders `open` even when saved closed.
  - **Site default:** `nav.collapsed` applies until the person chooses, and their choice wins after that.
  - **Badges:** a closed section's `<summary>` carries the summed badge.
  - **Endpoint:** it refuses without a sign-in and without a CSRF token.
- **By hand, in a browser, on BG Genius or holz-objekte.org:** close two sections, reload, and check them on another
  browser. Then check the keyboard (Tab to a heading, Enter or Space toggles it) and the mobile drawer.
