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
| `--c-type-*` (the type tints) and `--w-side-panel` are defined, but nothing reads them yet. | 0.75 | `.folio-type-icon` reads the type tints since 0.75 (one rule per `TypeFamily` value in `admin.css`); `--w-side-panel` sets the editor side panel's width since 0.75 (Task 4). |
| The type tile's radius is `--r-xs` (6px); the 0.75 plan named `--r-sm`, which is 8px. | Adaptation | COMPONENTS §1 asks for radius 6; the 28px list-header tile uses 7px, as on the List board. |
| The `live` type family is tinted with the accent (emerald: `--c-accent-text` on `--c-accent-soft`), as the HolzDashboard board tints CO₂-Rechner. Emerald also marks actions (primary buttons, the active nav item). | Adaptation | Ruling for 0.75: stay close to the board; the handoff's README keeps emerald for actions, so a live tile shares the action colour. If the owner objects, it is one CSS rule (`.folio-type-icon[data-family="live"]`). |
| `.folio-notice` is the info tone (blue) by default. Before 0.73 every notice was amber. `is-warning` or `role="alert"` gives the amber warning tone, and `folio-alert-error` the red. | Adaptation | COMPONENTS §3 makes the notice the info tone. Extension notices (`#[ListNotice]`, `#[DashboardNotice]`) that warn need `is-warning`. The README's Admin styling section and the attribute table say so. |
| Locale dots show complete and missing only, never "partial". The interface-language switch has no dots. | Fixed in 0.75 | The editor's content-language segmented shows all three states from `TranslationStatus::state()` (filled, warning ring, subtle ring; Task 4). The interface languages have no completeness data, so their switch keeps no dots. |
| The draft badge is filled with `--c-neutral-soft`, not the board's hover colour. | Adaptation | It has to stay visible on hovered rows (see the token above). |
| Error toasts (`folio:error`) show a warning glyph and have no countdown bar. | Adaptation | A check would misreport an error, and error toasts have no undo window. |
| In dark mode, the toast's check and bar mix ink-accent with inverse-fg. | Adaptation | The board's `#34d399` is too faint on the light inverse toast. |
| With reduced motion, the undo bar is hidden. The global reduced-motion rule also stops the busy spinner. | Adaptation | A bar that does not move would claim a countdown. A busy button still reads "Saving…" or "Publishing…". |
| Revision dots are all the neutral "line" dot. | Adaptation | Revision rows carry no draft or published state. |
| "N changes · Compare" in the revision list and the publishing panel is missing. | Fixed in 0.75 | The side panel's Publishing shows "N changes" (`Publisher::changes()`), opening the list of changed fields, "Languages" and "SEO & sharing". Compare is not built: the owner said no to a diff view (2026-11-13). |
| The drawer has no accent-soft "Saved" line. | Adaptation | `drawer_saved.twig` posts a message and closes the drawer at once, so there is no saved state to show. |
| Danger buttons are filled, as on the board. Unpublish and the asset's Move to bin are outlined danger buttons (`btn btn-secondary` with `data-tone="danger"`, red text and a red-tinted border); a delete for good keeps the filled `.btn-danger`. | Fixed in 0.73 | The board's Unpublish is outlined. The board's text-only "Move to bin" link comes with the asset detail redesign (0.76). |
| `.folio-filter-add` ("+ Filter", dashed) exists in CSS only. The list toolbar still uses the `details` Filter button. | Fixed in 0.75 | "+ Filter" is the dashed summary of a 300px popover (fields in groups, mono date range, Reset · Apply). |
| The review-queue card has no type icon. | Fixed in 0.75 | Each type's group heading shows its tile (`type_icon` macro: the type's icon on its family's tint, from `TypeListing::$family`). |
| The review-queue card shows the translated progress sentence in mono, not a bare "6 / 17". | Adaptation | The sentence keeps the translation keys. |
| Alert, notice, field-error, chip and toast glyphs are CSS masks (copies of the icons.md paths), not `<use>` icons. | Adaptation | Field-type PHP and flash markup stay unchanged. |
| The `is-failed` badge exists, but the AI run item status "failed" still renders `is-incomplete`. | 0.76 | It is a markup change in the AI run screen. |
| On a ticked list row, the accent-soft row fill hides the published badge's fill. The text and dot stay readable. | Fixed in 0.75 | On a ticked row, accent-soft badges and complete language items take the surface fill. |
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
| The editor topbar has a breadcrumb over the title. The board has a back button, the content-language and layout segmented controls, the save state, Save draft and the Publish split button. | Fixed in 0.75 | The editor action bar (Task 4). |
| The content column stops at 1120px while the topbar runs full width, so on wide screens the header's "New" sits to the right of the table edge. The List board fills the width; the Dashboard and settings boards are capped like Folio. | Fixed in 0.75 | Lists set `content_width = 'wide'` (`.folio-content.is-wide`); other screens keep 1120px. |
| There are no phone variants on the boards. At 375 the sidebar stacks into a site row, an avatar and one scrolling nav row (section headings as chips), and the rail becomes a top strip. | Adaptation | Task 3's phone layout. No page scrolls sideways at 375; tables scroll in their box. |
| There is no skip link to the main content. | 0.76 | It comes with the ink layer's shell touches. The rail and sidebar add up to 20+ tab stops before the content. |
| Checkbox and radio chips draw the tick as a CSS mask, which forced-colors mode (Windows High Contrast) may not paint. The chip's border and fill still change. | Proposal | A `@media (forced-colors: active)` fallback (the native box shown again). |
| With sign-in off (no user menu), the theme switch sits alone in the sidebar foot, so editor pages on the rail have no theme switch until the rail is expanded. | Adaptation | Sites without auth are rare (demo, local). The rail has no foot slot for the switch, and the choice made on any sidebar page applies everywhere. |
| The asset Details tab used the sidebar while its Edit image tab used the rail. | Fixed in 0.73 | Both tabs now use the rail (SCREENS: asset detail is an editor). |

## Screens that 0.73 did not redesign

| Screen | Board | Label |
|---|---|---|
| Lists: the toolbar (search with icon, status segmented with counts, chips, density), the table (12px head on surface-2 instead of uppercase, title plus meta line, mono numbers, one "…" menu instead of Edit and trash), the language indicator, the floating bulk bar with Translate, the pager ("1–8 of 17"), and the home marker on the pages list | List, PagesList | Fixed in 0.75 |
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
| Ink layer: marks in `icons.svg`, `.folio-ink-marks`, the marks macro, ink empty states, the 404 and forbidden ink cards (today forbidden is a plain card and an unknown admin path answers the plain text "Unknown type"), the sign-in switched to the shared classes (`.folio-caret` and the save status with caret came with the editor in 0.75) | Ink | 0.76 |
| The removal dialog restyle (radio cards, "Now: …") | LibOverlays | 0.76 (with redirects) |
| Styled confirm dialog, AI "Write a draft" in rich text, the "+" insert line and `/` block picker, ⌘K palette | LibOverlays, PageEditor, RecordEditor | Proposal |
| The share link's "Link copied · 7 days" state | LibOverlays | Fixed in 0.75 |

## Lists (0.75, Task 3)

Captured on a scratch starter-folio (pages 2 rows after a Duplicate, news 3, jobs 40, companies 7; a filter applied, rows ticked, a menu open, the `?` list, compact rows) at 1440 and 375, light and dark, beside the List and PagesList boards. No page scrolls sideways at 375; the table scrolls in its box (min-width 720px).

| Difference | Label | Why / what |
|---|---|---|
| Active filter chips sit in a row under the toolbar; the List board shows them inline after the search. | Adaptation | The chips belong to the region the live search swaps, so they stay current while typing; the toolbar is outside it. |
| The row menu follows the board (Edit, View on site, Copy preview link, Duplicate, divider, Unpublish and Delete… in red) and adds what the board does not show before the divider: Move up / Move down on orderable lists and the type's header record actions. | Adaptation | The board's pages list is not orderable and has no extension actions; they are the record's own actions, so they stay above the danger items. |
| Shortcut hints show only on Edit (E) and Duplicate (D). The board also shows V (View on site), L (Copy preview link) and ⌫ (Delete). | Adaptation | Only keys that exist get a hint; V, L and ⌫ were not in the shortcut set (a destructive one-key Delete especially). |
| The density toggle (Compact / Comfortable) sits where the List board has a list / grid view toggle. | Adaptation | There is no grid view of records; the brief asks for density. |
| The status column says "Changed" (the publishing state text), the board "+2 changes" next to "Published". | 0.75 | `Publisher::changes()` counts come with the editor side panel (Task 4); a per-row count would read every snapshot on the list. |
| Row tiles are 24px (board 26px). | Adaptation | The type tile macro has two sizes (24, 28); 26 would be a third for 2px. |
| The List board's "Changed" relative date column ("vor 3 Wochen") is not added. | Proposal | A column a type lists if it wants it (`list.columns`); a default updated-at column needs the owner's yes. |
| "Fehlende Sprachen übersetzen" header action shows as "Translate all…" whenever AI translation applies, not only when the type has gaps. | Adaptation | Knowing whether a type has gaps means reading every record's texts on each list view. |
| The bulk bar's actions follow the type: no "Set …", Translate or Export where the type has no bulk field, no AI or no OpenSpout. | Adaptation | Same rules as before; the board shows a type that has them. |
| On a phone the row menu opens next to its button clamped into the viewport, even when the button is scrolled out of the table's box. | Adaptation | The panel floats (position: fixed) so the scroll box cannot cut it off. |

## Editor frame (0.75, Task 4)

Captured on a scratch starter-folio (a page with blocks, a news post in English and German with a partial German, a job with half-width fields, a side field and read-only fields, a post with changes not live, a draft, a new record, a read-only view, the site singleton; the split menu and the side panel sheet open) at 1440 and 375, light and dark, beside the PageEditor, RecordEditor and ReferenceEdit boards. No page scrolls sideways at 375.

| Difference | Label | Why / what |
|---|---|---|
| "Publish at" sits in the Publish split button's menu; the PageEditor and ReferenceEdit boards also show it in the side panel's Publishing section. | Adaptation | COMPONENTS §1 moves it into the split button, and one date input in one place cannot disagree with itself. The side panel says "Scheduled to publish on …" when a date is set, and the button reads "Schedule". |
| No "Scheduled" badge next to the title. | Adaptation | The state badges are the publishing states (Published, Changes not live, Draft); a schedule is a date on the working copy, shown by "Schedule" and the side panel's note. |
| The address field has no `/` affix and copy button yet. | 0.75 | Task 6 builds the affix on the string field (`data-folio-address`); the field already sits in the side panel's Address. |
| The save state reads "Saved · not published" when a page with a draft is loaded, "Draft saved 19:37" only after a save in the page. | Adaptation | The time of the last save is not stored; inventing one would be wrong. "No changes" when the working copy is live, as on ReferenceEdit. |
| Below 1280px the side panel follows the main column as one card (with the preview: under the editor and preview); at phone width it is a sheet behind a topbar button ("Publishing and details"), Esc closes it. | Adaptation | The boards show 1600px only. Under the form below 1280px is the brief's rule; on a phone the panel would push the form a long way down, so it waits behind a button, and works in place without script. |
| On a phone the topbar (with Save draft and Publish) scrolls away with the page; there is no floating publish box any more. | Proposal | The brief removes the floating box (it hid "Add block"). Autosave keeps a publishing type's work; a compact sticky phone bar (Save + Publish only) would bring the buttons back on long forms. |
| Revisions in the side panel list who, when and the changed fields, with Restore; the board's state words ("Draft saved", "Published", "current") and coloured dots are not there. | Adaptation | Revision rows carry no state (see the revision-dot row above). |
| The search result shows the host of `app.url`; without one, the path alone. | Adaptation | The admin should show the canonical address, not whatever host the admin was opened on. |
| The search result is the saved one; typing a share title does not update it until autosave or a reload. | Proposal | Updating it live needs the same fallbacks the server applies (share text, then the type's fields, then the site's description) in script. |
| The tab bar sits inside the 760px column; the PageEditor board runs its underline across the whole form pane. | Adaptation | The column is the reading width; at 480px next to the preview the two are the same. |
| The publish-at hint ("in 2 days") comes from a small `relativeWhen()` in `editor-frame.js`, not from the date field's relative hint. | 0.75 | Task 6 builds the date field's hint helper; `relativeWhen()` (marked `TODO(Task 6)`) is replaced by it then, so there is one helper. |
| The extension record actions moved to the side panel's foot. The board shows none. | Adaptation | The brief's place for them. (Copy preview link moved to the preview bar, where the board has it; Task 5.) |

## Blocks and preview (0.75, Task 5)

Captured on a scratch starter-folio (a draft page with seven blocks: two heroes, two texts, latest jobs, latest news and an unknown block; the starter's blocks now declare icons and families) at 1440 and 375, light and dark, beside the PageEditor board: the first card open and selected in the split preview, the picker open, filtered and empty, a card's menu open, an invalid card after Publish, and "Link copied · 7 days". No page scrolls sideways at 375.

| Difference | Label | Why / what |
|---|---|---|
| The picker's popover opens in the page flow under the "Add block" button, which stays (pressed); the board shows the popover where the button was. | Adaptation | Escape returns the focus to the button, so it has to stay; in the flow the popover is never cut off by the form column's scroll box and works the same on a phone. |
| Below 360px of form width the picker lists its items in one column; the board shows two. | Adaptation | Two columns of labels at phone width cut most names off. |
| Every open card has the accent border; the board shows one open card. | Adaptation | The brief: open = selected. Several cards can be open (Expand all); the preview outlines the one last opened or focused. |
| In a form column under 380px wide the cards show no summary line, except an error. | Adaptation | Label and summary do not both fit at phone width; an error must still show. |
| After Copy preview link the share answer (the address, Copy, Revoke all links) shows under the preview bar; the board shows only the button's "Link copied · 7 days". | Adaptation | Revoke has no other place, and without a clipboard (an http address, a browser that refuses) the answer is how the link is copied. |
| The preview bar keeps the Focus toggle; the board does not show it. | Adaptation | The brief keeps it (focus mode: opening a block from the preview closes the others). |
| The URL pill's dot is accent while the page is live and unchanged; the board shows only the draft's warning dot. | Adaptation | A dot in both states keeps the pill's layout steady; the colour says which. |
| The URL pill shows the host of `app.url` without its port (`localhost/home-2` on a dev server). | Adaptation | As the side panel's search result: the canonical address, not the host the admin runs on. |

