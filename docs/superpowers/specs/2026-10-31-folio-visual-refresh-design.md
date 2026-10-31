# Folio visual check and refresh — design

Date: 2026-10-31. Owner-approved (dark palette: "soft graphite" from the visual companion). **(my call)** = decided while
writing, open to review. Target release: Preflow 0.48.4 (owner approved a framework release for this).

## Why

Several Folio screens look unfinished: raw browser controls (AI translation glossary table: plain text inputs and a
native multi-select "Types" list box), select arrows sitting on the field's right border, a filter bar (group,
language, search, buttons) that doesn't align and wraps a stray button, and the single-relation field rendered as an
old-style `fieldset` with the label in the frame (seen on holz-objekte.org "Objekt"). The dark mode is starkly dark
(#141413). The owner wants the issues fixed and is open to a further refresh.

Cause found so far: control styles in `packages/folio/assets/admin.css` are scoped to specific containers
(`.form-group`, `.folio-field`, `.folio-list-toolbar`, `.folio-assets-toolbar`, `.folio-translations` …), so screens
with other wrappers (package screens, extension screens) get native controls; selects keep the native appearance.

## Goals

1. **Base control styles for the whole admin** (inside Folio's admin root, not leaking into previews/iframes of the
   site): text-like inputs, number, email, url, search, date, textarea, select, multi-select, checkbox, radio, file —
   one height (`--h-control`), radius, border, background, focus ring, disabled and error states. Container-specific
   rules only for genuine differences.
2. **Select arrow** drawn by Folio (`appearance: none` + an inline SVG/CSS chevron, theme-aware), with right padding so
   the arrow sits inside the field; same for selects in toolbars and tables.
3. **Toolbars / filter bars**: one flex row, aligned to the control height, gap tokens, labels inline; wraps cleanly
   on narrow screens (no orphaned button on its own line unless the row is genuinely too narrow).
4. **Multi-selects** for small option sets (the AI glossary "Types", similar `select[multiple]`) become checkbox chips
   (the vocabulary of `.folio-relation-multi` / rbac permission chips). Large option sets keep a styled list box
   **(my call: chips when ≤ ~12 options)**.
5. **Single relation field**: label above like every other field (no `legend` in the border), the chosen item as a
   chip, search below, help text muted — same structure as the multi relation.
6. **Tables with inputs** (AI glossary, translations, matrix): even cell padding, inputs filling their cell, row
   actions aligned, the "Remove" checkbox as a small icon-button or aligned checkbox.
7. **Dark mode → soft graphite**: lift the dark palette (reference values from the approved mockup: page
   `#1e1f22`, sidebar `#232428`, surface `#2a2b2f`, input `#25262a`, border `#36373c`, strong border `#47494f`, text
   `#e9e9ec`, muted `#a3a5ad`, accent `#3fcf98`, accent-fg `#062a1d`, accent-soft `#203a31`, accent-text `#6fe2b4`);
   derive the remaining dark tokens (raised, row hover, danger/warning soft, inverse, shadows, overlay) consistently.
   Light mode unchanged except where the fixes touch it.
8. **General refresh where cheap and consistent**: card edges (subtle border + soft shadow), even radii, consistent
   focus ring, spacing between form groups, headings weights. No layout restructuring of screens.

## Method

- Inventory: every admin template (`packages/folio/templates/admin/**`) and every package screen that renders inside
  Folio (ai, i18n translations, media/assets, rbac, members, codes, seo if present), plus the sites' extensions
  (BG Genius `app/Folio/BgGenius/**`, holz-objekte.org's Folio extensions) for markup that relies on unstyled controls.
- Visual check: screenshots of the key screens in light and dark before and after, on a local site with a signed-in
  staff session (the owner signs in once in the browser tab used for checks; credentials are never typed by the
  agent). Without a session: fix from code, list the screens for the owner to check.
- Tests: the existing Folio PHP tests stay green; add tests where markup changes (e.g. the relation field renders a
  label, not a legend; the AI glossary types render chips); CSS-only changes need no test.

## Answers to the builder check

- **Extensions**: markup in the sites' Folio extensions that relies on unstyled controls (BG Genius
  `app/Folio/BgGenius/**`, holz-objekte.org's extensions) is fixed in those repos as part of this work, after the
  framework part; holz-objekte.org is deployed with the 0.48.4 upgrade, BG Genius with its next deploy **(my call)**.
- **Multi-select → chips**: one reusable Folio partial/macro for "checkbox chips" that posts the same field name as an
  array (`name[]`), so servers that read the `select[multiple]` value keep working; used for small option sets
  (≤ ~12), large sets keep a styled list box. Each converted screen gets a test that the posted shape is unchanged.
- **Single relation field**: its DOM may change (fieldset/legend → label + container); the builder audits
  `admin.js` and any extension JS for selectors on the old structure and keeps form posts identical (tests for the
  posted value).
- **Visual check without a staff session**: fixing from code plus a list of screens for the owner to check counts as
  done; screenshots are taken when a signed-in session is available.

## Constraints

- Framework rule: Folio is the UI adapter; no project-specific styles in the framework. Site extensions adapt to Folio's
  classes (BG Genius and holz-objekte.org fixes, if any, happen in those repos).
- Strict CSP (no inline styles/scripts).
- Release 0.48.4 after review; upgrade and deploy smeyer.me, smart-stack.works and holz-objekte.org (standing rule);
  BG Genius picks it up with its next deploy.

## Non-goals

- No new Folio features, no navigation or information-architecture changes, no light-mode recolour.

## Left to the builder

- The exact SVG for the chevron, the derived dark tokens, the order of fixes.
