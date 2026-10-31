# Folio visual check list (0.48.4)

Status: **owner to check** — no signed-in staff session was available while building, so every screen below was
fixed from the code only. Check each in light and dark (sidebar theme switch: sun / moon), at desktop width and once
at ≤ 400px (browser dev tools). Paths assume the prefix `/folio`.

Everywhere: in-page surfaces (cards, tables, panels, toolbars, history) have a thin border, a soft shadow and the
same 10px corners; text fields, selects and textareas are 36px high with the same border and green focus ring; selects
show a thin chevron inside the field; nothing shows a raw browser control. Dark mode is soft graphite, not black.

## Sign-in pages

**`/folio/login`, `/folio/forgot`, `/folio/password?token=…`**
- Card centred, fields full width and 36px, "Remember me" checkbox on one line with "Forgot password?".
- Error message red on a soft red tint. Dark: card a step lighter than the page, fields darker than the card.
- ≤ 400px: the scene sits behind the card; no horizontal scroll.

## Shell and dashboard

**`/folio`** (dashboard)
- Type cards: even grid, 10px corners, hover lifts slightly. "Waiting for review" heading the same size as other
  section headings (not larger); job progress bars thin, rounded, green fill on a grey track (dark too).
- Sidebar: active item green-tinted; theme and language switches line up. ≤ 760px: sidebar becomes a top bar.

**`/folio/_account`**
- Panels ("Language", "Change password") with border + soft shadow; language select with chevron; fields 36px.

**A page you may not open** (e.g. a type without `.view`)
- Dashed empty box; the help line under the message is muted grey (it was plain text before).

## Content lists

**`/folio/{type}`** (any list, e.g. pages, posts)
- Toolbar: search, "Add filter", "Search" all 36px on one line; filter panel's selects and date inputs styled.
- Table in a bordered card; tick a row → dark bulk bar lies over the header with matching rounded top corners.
- Language and state badges; pager below. ≤ 400px: toolbar wraps to full-width rows; the table scrolls inside its card.
- No records / no matches: dashed empty box.

## Edit form

**`/folio/{type}/{id}/edit`** and **`/new`**
- Labels above every field; field groups evenly spaced (a Blocks field no longer leaves a bigger gap).
- Boolean ("Aktiv" etc.): box inline with its label, help indented below.
- Select with `display: radio`, relation fields, Rollen: chips under the label, no fieldset border.
- Blocks: cards with a soft shadow; open card stronger shadow; nested cards flat.
- Matrix field: Up / Down / Remove are now Folio ghost buttons (Remove turns red on hover), as tall as the selects.
- "Still correct" buttons next to "outdated / unchecked" hints are small (24px), not full button height.
- History ("Versions") and the preview-link box: border + soft shadow; side panels (Account, Decision, Codes) too.
- Sticky save bar at the bottom: floating, rounded, blurred.
- Error state: submit a required field empty → red border on inputs/selects; on chip groups every chip red, also a
  disabled (denied) role chip; on a boolean the label turns red and the box gets a red ring; all messages listed.
- Read-only (a user without edit right): everything muted but readable; chips and the boolean label grey with a
  normal cursor.
- Split view (a previewable type): form pane, resizer, preview bar; device frame rounded.
- "Create new" in a matrix/relation opens the drawer: its "Close" is now a small Folio button (was a raw button).

## Asset library

**`/folio/assets`** (grid) and **`?view=list`**
- Toolbar card: search, kind select, filters, "Filter" button and upload button all 36px and aligned; the
  "Only unused" checkbox (cleanup on) sits inline with its label; Grid/List switch.
- List view: the table now sits in a bordered card like every other table (it was bare on the page).
- Picker (Choose from a field): same, with "Select" buttons.

**`/folio/assets/bin`**
- Bin table in a bordered card (was bare); "Restore" buttons aligned.

**`/folio/assets/{id}`** (details) and **`/folio/assets/{id}/edit`** (Edit image)
- Panel with 10px corners; alt/title fields and extra fields evenly spaced (extra fields no longer double-spaced).
- "Earlier versions" sub-heading small (14px), not bigger than "File".
- Edit image: tools, ratio select with chevron, format list.

## Settings

**`/folio/translations`**
- Filter bar: Group, Language (inline labels, selects with chevron), search, "Search", and "Translate" at the right.
- After an AI fill or a refused save: the "Save all filled" bar shows as one row (button + hint), with space below.
- Each row: text field or textarea, Save / Reset buttons in one line; an error message on its own line below.

**`/folio/ai`** (status)
- Key/value table: row labels left-aligned like the cells (they were centred and bold).
- The test result and the "Test" button have space above them; each month is its own section with space above,
  its "(label)" muted.

**`/folio/ai/tuning`**
- Each level a textarea under its label. "Add a field level": label above, no border; "Key", help, then "Text" with
  a normal gap before the second label (it touched the help line before).

**`/folio/ai/glossary`**
- Table of inputs in a card; "Keep" checkboxes aligned; "Types" as chips; "Remove" column centred.
- "Add row" / "Save" have space above (they touched the table before).

**`/folio/{type}/_ai/run`** (Translate all, from a list)
- Language checkboxes and scope radios in rows; note textarea; an unfinished job is one row (badge, text,
  confirm box, "Continue").
- After Start: progress bar thin and rounded with green fill (dark too); current record bar thinner; records table in
  a card.

**`/folio/extensions`**, **`/folio/cache`**
- Tables in cards, Enable/Disable and Clear buttons right-aligned; OPcache heading the same weight as other headings.

**Staff / members admin** (`/folio/team/{id}/edit`, a member record)
- Rollen chips; denied role chip muted with tooltip; Account panel badges and buttons; member Decision panel tabs
  (Accept / Decline), textarea, buttons. Permissions editor (role record): "Everything" card with 10px corners.

## Known, left as is

- Focus: links and plain buttons show a 2px green outline, `.btn` buttons and fields a soft green ring — both green,
  deliberately unchanged.
- Codes "Redemptions" side panel: a plain table inside the panel (no second card around it).
- Dialogs, the drawer, the sign-in card and the sticky save bar keep their larger 14px corners and stronger shadow:
  they float above the page.
