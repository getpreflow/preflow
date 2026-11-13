# Folio redesign: differences from the boards (Preflow 0.73)

Release 0.73 builds the first part of the owner's handoff (`.superpowers/folio-redesign/folio-redesign-handoff/`):
tokens (PLAN phase 1), primitives (phase 2) and the shell (phase 3). This note lists every difference
between the admin and the boards that is still there after the visual pass (Task 4), and what happens
to it.

How it was compared: every admin screen was captured with headless Chrome at 1440 and 375 px, in light
and dark. The screens were dashboard, every list, a page with blocks, a record, a role, assets, asset
details and Edit image, translations, both redirect tabs, the new-redirect form, cache, SEO audit,
extensions, account, login, forbidden and 404. They ran against a scratch copy of `starter-folio` with
its demo data. All 19 boards in `reference/*.dc.html` were rendered the same way, in both themes, and
each screen was set beside its board.

Each item has exactly one of five labels. The redesign releases shifted: 0.74 is a separate SEO-audit
release, so lists and editors come in 0.75 and settings, library screens and ink in 0.76.


- **0.75**: in scope for 0.75, which covers lists and editors (PLAN phases 4–5).
- **0.76**: in scope for 0.76, which covers settings, library screens and the ink layer (phases 6–7).
- **Adaptation**: a deliberate difference, with the reason.
- **Proposal**: needs the owner's yes before anyone builds it (phase 8, or new).
- **Fixed in 0.73**: a difference that Task 4 found and closed in this release. It is listed so the
  record is complete.

Each row carries exactly one label.

## Tokens and type (phase 1)

| Difference | Label | Why / what |
|---|---|---|
| Inter and JetBrains Mono were not shipped: the stacks named them first and fell back to the system fonts, with no `@font-face`. | Fixed in 0.75 | Self-hosted now: `InterVariable.woff2`, `JetBrainsMono-Regular.woff2` and `-Medium.woff2` with their OFL licences in `assets/fonts/`, served by the asset controller and loaded by `@font-face` in `admin.css`. Inter is preloaded in the layouts. |
| Dark `--c-neutral-soft` is `#2d3833`, not the handoff's `#26302b`. | Adaptation | The handoff value equals `--c-row-hover`, so draft badges disappeared on hovered rows (AdminCssTest). |
| `--focus-ring` is a new token: a 2px surface gap, then a 2px solid accent ring. Buttons, chips, danger buttons, the asset view toggle and the rbac switch and chips use it. Native checkbox, radio and file inputs get the accent outline. | Adaptation | The boards draw no focus states. The soft `--c-focus-ring` halo measured about 1.5:1, while the new ring is at least 4.7:1 on every surface in both themes. Text fields keep the board's accent border plus soft ring. |

## Primitives (phase 2)

| Difference | Label | Why / what |
|---|---|---|
| `--c-type-*` (the type tints) and `--w-side-panel` are defined, but nothing reads them yet. | 0.75 | They are reserved for `.folio-type-icon` and the editor side panel. `admin.css` says so next to them. |
| `.folio-notice` is the info tone (blue) by default. Before 0.73 every notice was amber. `is-warning` or `role="alert"` gives the amber warning tone, and `folio-alert-error` the red. | Adaptation | COMPONENTS §3 makes the notice the info tone. Extension notices (`#[ListNotice]`, `#[DashboardNotice]`) that warn need `is-warning`. The README's Admin styling section and the attribute table say so. |
| Locale dots show complete and missing only, never "partial". The interface-language switch has no dots. | Adaptation | There is no data for partial. The interface languages have no completeness data. |
| The draft badge is filled with `--c-neutral-soft`, not the board's hover colour. | Adaptation | It has to stay visible on hovered rows (see the token above). |
| Error toasts (`folio:error`) show a warning glyph and have no countdown bar. | Adaptation | A check would misreport an error, and error toasts have no undo window. |
| In dark mode, the toast's check and bar mix ink-accent with inverse-fg. | Adaptation | The board's `#34d399` is too faint on the light inverse toast. |
| With reduced motion, the undo bar is hidden. The global reduced-motion rule also stops the busy spinner. | Adaptation | A bar that does not move would claim a countdown. A busy button still reads "Saving…" or "Publishing…". |
| Revision dots are all the neutral "line" dot. | Adaptation | Revision rows carry no draft or published state. |
| "N changes · Compare" in the revision list and the publishing panel is missing. | 0.75 | It belongs to the editor side panel and needs diff data. |
| The drawer has no accent-soft "Saved" line. | Adaptation | `drawer_saved.twig` posts a message and closes the drawer at once, so there is no saved state to show. |
| Danger buttons are filled, as on the board. Unpublish and the asset's Move to bin are outlined danger buttons (`btn btn-secondary` with `data-tone="danger"`, red text and a red-tinted border); a delete for good keeps the filled `.btn-danger`. | Fixed in 0.73 | The board's Unpublish is outlined. The board's text-only "Move to bin" link comes with the asset detail redesign (0.76). |
| `.folio-filter-add` ("+ Filter", dashed) exists in CSS only. The list toolbar still uses the `details` Filter button. | 0.75 | It comes with the list toolbar and the filter popover. |
| The review-queue card has no type icon. | 0.75 | `.folio-type-icon` comes with the lists. |
| The review-queue card shows the translated progress sentence in mono, not a bare "6 / 17". | Adaptation | The sentence keeps the translation keys. |
| Alert, notice, field-error, chip and toast glyphs are CSS masks (copies of the icons.md paths), not `<use>` icons. | Adaptation | Field-type PHP and flash markup stay unchanged. |
| The `is-failed` badge exists, but the AI run item status "failed" still renders `is-incomplete`. | 0.76 | It is a markup change in the AI run screen. |
| On a ticked list row, the accent-soft row fill hides the published badge's fill. The text and dot stay readable. | 0.75 | Part of the table restyle. |
| The asset screens' failure messages now use `folio-alert-error`; before, they used the warning tone. | Fixed in 0.73 | Uploads, moves to the bin, panel and edit errors. |
| The Redirects and AI settings tab bars are now `.folio-tabs` (underline tabs, `aria-current`); before, they were rows of small buttons. | Fixed in 0.73 | Same links, same keys. |

## Shell (phase 3)

| Difference | Label | Why / what |
|---|---|---|
| The rail shows one icon per nav item, with lines between sections. The holz board shows one icon per section. | Adaptation | Owner ruling. The nav model has no section icon or section link. A long rail scrolls, and the current item is scrolled into view (Task 4). |
| The rail has an expand button, and the sidebar has a collapse button on editor pages. | Adaptation | An expanded rail must be able to come back within the session. The boards show the expand button only. |
| The site switcher is a link to the dashboard, with no chevron. | Adaptation | There is one site. A site list comes with a second site. |
| In production the environment line reads only "Folio". The holz board shows "Folio · Produktion". | Adaptation | The line marks the environments that are not live. One condition in `FolioServiceProvider::environment()` if the owner wants production named too. |
| The brand tile is plain `--ink` with radius 9. The full `.folio-ink` glow is kept for the 0.76 ink places. | Adaptation | At 32px the glow tints the whole tile, and the board's tile is plain. |
| The user menu is a non-modal dialog (`aria-haspopup="dialog"`), not `role="menu"`. "Account & password" only shows for record staff accounts. | Adaptation | Owner ruling. The theme and language rows are toggle groups and a POST form, which a menu may not hold. Config users have no account page. |
| Without script, the sidebar's user panel stays open in place, and the button says `aria-expanded="true"`. If admin.js fails, the head script drops `folio-js` at `load` and the same layout shows. On editor pages without script, the rail's panel stays shut. | Adaptation | Log out and the language must stay reachable. The rail is too narrow for an open panel; its brand tile leads to the dashboard, whose sidebar has the menu. |
| The sidebar's search entry ("Search or jump to… ⌘K") is not built. | Proposal | ⌘K palette, phase 8. |
| Nav items have no plain count of entries (board: "Pages 2", "Objekte 436"). Nav badges stay the warning pill for "needs attention" counts. | Proposal | The nav model has no totals, and counting every type on every request costs queries. `#[NavBadge]` stays "needs attention". |
| Nav and title icons are the icon each model configures, so several types share the sliders icon. The board has tinted type icons. | 0.75 | `.folio-type-icon` and the type tints come with the lists. |
| Long subtitles wrap; for example, the not-found intro is four lines at 1440. The board's subtitle is one short line. | Adaptation | Cutting them would hide the explanation. |
| Shorter subtitle copy, so every subtitle fits one line as on the boards. | Proposal | A copy change the owner decides on. The keys stay. |
| The editor topbar has a breadcrumb over the title. The board has a back button, the content-language and layout segmented controls, the save state, Save draft and the Publish split button. | 0.75 | Editor action bar. The rail shell's topbar is already compact (17px title). |
| The content column stops at 1120px while the topbar runs full width, so on wide screens the header's "New" sits to the right of the table edge. The List board fills the width; the Dashboard and settings boards are capped like Folio. | 0.75 | It changes with the list layout (full-width lists). |
| There are no phone variants on the boards. At 375 the sidebar stacks into a site row, an avatar and one scrolling nav row (section headings as chips), and the rail becomes a top strip. | Adaptation | Task 3's phone layout. No page scrolls sideways at 375; tables scroll in their box. |
| There is no skip link to the main content. | 0.76 | It comes with the ink layer's shell touches. The rail and sidebar add up to 20+ tab stops before the content. |
| Checkbox and radio chips draw the tick as a CSS mask, which forced-colors mode (Windows High Contrast) may not paint. The chip's border and fill still change. | Proposal | A `@media (forced-colors: active)` fallback (the native box shown again). |
| With sign-in off (no user menu), the theme switch sits alone in the sidebar foot, so editor pages on the rail have no theme switch until the rail is expanded. | Adaptation | Sites without auth are rare (demo, local). The rail has no foot slot for the switch, and the choice made on any sidebar page applies everywhere. |
| The asset Details tab used the sidebar while its Edit image tab used the rail. | Fixed in 0.73 | Both tabs now use the rail (SCREENS: asset detail is an editor). |

## Screens that 0.73 did not redesign

| Screen | Board | Label |
|---|---|---|
| Lists: the toolbar (search with icon, status segmented with counts, chips, density), the table (12px head on surface-2 instead of uppercase, title plus meta line, mono numbers, one "…" menu instead of Edit and trash), the language indicator, the floating bulk bar with Translate, the pager ("1–8 of 17"), and the home marker on the pages list | List, PagesList | 0.75 |
| Editors: the action bar, removing the floating publish box, the 320px side panel (publishing, address with copy, languages, search preview, revisions, system fields, Unpublish/Delete), Content / SEO & sharing tabs instead of the disclosure, block cards with type icons, the grouped searchable block picker, the preview bar (device toggle, URL pill), the ownership card and relation rows | PageEditor, RecordEditor, ReferenceEdit | 0.75 |
| Fields: affix and copy on slug and path, the counter on text, the Trix toolbar, the number stepper, the date with relative hint, the switch variant, segmented and radio cards for selects, relation rows, the asset field drop zone, matrix rows, env set/not set | LibFields | 0.75 (editor fields) |
| Dashboard: the subtitle and header actions (View site, New page split), the ink band with the mono stats line, "Needs you" cards, and the Content list with type icons and quick create | Dashboard, HolzDashboard | 0.76 (ink band, dashboard) |
| Dashboard: Recent changes | Dashboard | Proposal (needs an activity source) |
| Translations: the group select with its label inside, the language segmented, "Changed only · N", fixed table layout, `:min` tokens, inline inputs with changed rows and reset, the sticky save-all bar. 0.73 only gives the key and delivered columns a minimum width, so the key stays readable at 375. | Translations | 0.76 |
| Redirects: the "from → to" mono table, the not-found count badge on the tab, the drawer form with "Send visitors to" segmented and the 301/302 radio cards | Redirects | 0.76 |
| Cache: the total with a stacked bar, size bars and per-row Clear, "Clear all…" in the header, page cache and OPcache as cards | Cache | 0.76 |
| Roles: the left card with the key in mono, the matrix grouped by section with type icons, row switches instead of "All", dashed implied ticks, "Neu" badges and the "Nur neue" filter, the unsaved count in the header | Roles | 0.76 |
| Assets: the header with Bin and Upload, the toolbar (kind segmented, grid/list toggle), 16:10 cards with mono names and meta, the drop zone as the last card, the selection bar | Assets | 0.76 |
| Assets: the "Missing alt text · N" filter | Assets | Proposal |
| Asset detail: the large preview on surface-2, the right panel (alt warning, file facts, swatches, Used in with type icons, Replace drop zone), the Versions tab | AssetDetail | 0.76 |
| Asset detail: crop previews (16:9, 4:3, 1:1, 9:16) | AssetDetail | Proposal |
| Ink layer: marks in `icons.svg`, `.folio-ink-marks`, `.folio-caret`, the marks macro, ink empty states, the 404 and forbidden ink cards (today forbidden is a plain card and an unknown admin path answers the plain text "Unknown type"), the save status with caret, the sign-in switched to the shared classes | Ink | 0.76 |
| The removal dialog restyle (radio cards, "Now: …") | LibOverlays | 0.76 (with redirects) |
| Styled confirm dialog, AI "Write a draft" in rich text, the "+" insert line and `/` block picker, ⌘K palette | LibOverlays, PageEditor, RecordEditor | Proposal |
| The share link's "Link copied · 7 days" state | LibOverlays | 0.75 (editor preview bar) |
