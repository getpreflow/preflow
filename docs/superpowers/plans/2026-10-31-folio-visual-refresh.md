# Folio Visual Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every Folio screen uses styled, consistent controls; select arrows, filter bars, multi-selects and relation fields are fixed; dark mode becomes "soft graphite".

**Architecture:** Base control styles move from container-scoped selectors to the admin root in `packages/folio/assets/admin.css`; a reusable checkbox-chips partial replaces small `select[multiple]`; `SelectFieldType`/`RelationFieldType` render label-above structures. Site extensions are adjusted in their own repos.

**Tech Stack:** CSS (light-dark() tokens), Twig, PHP 8.4+, PHPUnit, node tests for admin.js (`node --test 'packages/folio/tests/js/*.test.mjs'`).

**Spec:** `docs/superpowers/specs/2026-10-31-folio-visual-refresh-design.md`

## Global Constraints

- Dark palette (soft graphite): page `#1e1f22`, sidebar `#232428`, surface `#2a2b2f`, input `#25262a`, border `#36373c`, strong border `#47494f`, text `#e9e9ec`, muted `#a3a5ad`, accent `#3fcf98`, accent-fg `#062a1d`, accent-soft `#203a31`, accent-text `#6fe2b4`; other dark tokens derived consistently. Light mode unchanged except fixes.
- Controls: one height `--h-control` (36px), `--r-sm` radius, same border/background/focus ring everywhere inside the admin root; never leak into site previews/iframes.
- Chips for `select[multiple]` with ≤ 12 options; posted field name and array shape unchanged (`name[]`).
- Relation/select fields: label above (no legend in a border); posted values unchanged.
- Strict CSP: no inline styles/scripts. No project-specific styles in the framework.
- Full suite `vendor/bin/phpunit` at the repo root (≈4940 tests, 10-minute timeout) and the node tests stay green.
- Work on branch `feat/folio-visual-refresh` from `main`; no tag/push/merge (controller releases 0.48.4).

## Review Focus

1. A screen with controls outside any known container (package screens: ai glossary/tuning, translations, codes, rbac, members admin) → styled like form fields.
2. Disabled read-only forms (`fieldset disabled`) → controls look disabled but readable.
3. Long option labels in chips and in selects → wrap/ellipsis, no overflow of the field.
4. Narrow screens (≤ 400px) → toolbars wrap into full-width controls, no horizontal scroll.
5. Error state (`.has-error`) on every control type → visible red border, message below.

---

### Task 1: Tokens and base control styles

**Files:** Modify `packages/folio/assets/admin.css` (tokens block at the top; control rules around lines 448–640); Test: existing `packages/folio/tests/Assets/AdminJsTest.php`-style asset test if one asserts CSS, else none (CSS only).

- [ ] Replace the dark side of every `light-dark()` token with the soft graphite values (Global Constraints) and derive the rest (raised, row hover, neutral/danger/warning soft, inverse, overlay, shadows tuned for a lighter base).
- [ ] Identify the admin root element (body/wrapper class in `templates/admin/_layout.twig` and `_drawer_layout.twig`, `_auth_layout.twig`) and write one set of base rules scoped to it for `input:not([type=checkbox],[type=radio],[type=file],[type=hidden],[type=color],[type=range])`, `textarea`, `select` (single and multiple): height, padding, radius, border, background, color, hover, focus ring, disabled, `.has-error`, placeholder colour. Keep container rules only where they differ (margins, widths).
- [ ] Select chevron: `appearance:none`, a theme-aware chevron (CSS mask or inline-SVG data URI in CSS — CSP allows data: images only if the CSP img-src permits it; check `config`/CSP headers in the folio package and use a CSS-only chevron if not), `padding-right` so text never runs under it, arrow ~12px from the right edge. Multiple selects: no chevron.
- [ ] Checkbox/radio: keep `accent-color`, align with labels (`.form-radio`, `.form-check`).
- [ ] Run the full suite; commit `style(folio): soft graphite dark mode and admin-wide control styles`.

### Task 2: Toolbars, filter bars and tables with inputs

**Files:** Modify `admin.css`; templates where markup needs a wrapper class: `templates/admin/translations/index.twig` (group/language/search/translate bar), `templates/admin/ai/glossary.twig` (table), list/asset toolbars if inconsistent.

- [ ] One toolbar pattern (`.folio-toolbar` or reuse `.folio-list-toolbar`): flex row, `align-items:center`, gap tokens, inline labels, controls at `--h-control`, buttons at the same height; wraps to full-width controls under ~560px. Apply it to the translations filter (the "Übersetzen"/Translate button sits in the same row, right-aligned when space allows).
- [ ] Tables with inputs (`.folio-table-form` or the existing table class): cell padding even, inputs `width:100%` of their cell, checkbox cells centred, header row aligned with the inputs' left edge.
- [ ] Test: a render test for the translations index asserting the toolbar wrapper class exists (follow existing `tests/Http/*` patterns).
- [ ] Full suite; commit `style(folio): aligned filter bars and form tables`.

### Task 3: Checkbox chips for small multi-selects

**Files:** Create `packages/folio/templates/admin/partials/checkbox-chips.twig` (or a Twig macro file following the package's partial conventions); Modify `templates/admin/ai/glossary.twig` (Types), any other `select[multiple]` in Folio/package admin templates with ≤ 12 options (find with grep; `assets/index.twig` multiple is a different concept — leave it); `admin.css` (reuse `.folio-relation-multi` chip styles, generalised to `.folio-chips`).

**Interfaces:** Produces: partial with params `name` (string, posted as `name[]`), `options` (map value→label), `selected` (list), `label` (aria/legend text), `help` (optional).

- [ ] Failing test: the AI glossary page renders checkbox chips named `rows[N][types][]` with the right checked state and no `<select multiple>`; posting the form with two types saves both (existing glossary save test extended).
- [ ] Implement; chips keyboard-accessible (real checkboxes, visible focus).
- [ ] Full suite; commit `feat(folio): checkbox chips replace small multi-selects`.

### Task 4: Label-above select and relation fields

**Files:** Modify `packages/folio/src/Field/Types/SelectFieldType.php` (~106–119: radio/checkbox group in fieldset/legend), `RelationFieldType.php` (~73–193: multi and remote single relation fieldsets), `admin.css` (`.folio-relation*`), `assets/admin.js` (selectors on the relation markup — audit `data-folio-relation-*` usage); tests in `packages/folio/tests/Field/*`.

- [ ] Keep a semantic group for radio/checkbox lists (`role="group"` + `aria-labelledby` on a wrapper div with a normal `<label>`-styled heading), so screen readers keep the grouping without the bordered fieldset look; or keep `fieldset` but style it borderless with the legend rendered like a label (`float:none; padding:0;` — choose whichever renders identically across browsers and say so in the report).
- [ ] Remote single relation: label above, chosen item as a chip, search input below, help text muted, same container classes for JS (`data-folio-relation-remote`, `data-folio-relation-name` unchanged).
- [ ] Tests: field render tests assert the label text appears as the field label and the posted input names are unchanged; existing relation JS tests (if any) pass.
- [ ] Full suite + node tests; commit `style(folio): select and relation fields with the label above`.

### Task 5: General refresh pass and screen list

**Files:** `admin.css`; Create `packages/folio/docs/visual-check.md` (or `docs/superpowers/plans/2026-10-31-folio-visual-check-list.md`): every admin screen/route with what to look at, light and dark.

- [ ] Cards (`.folio-card`, panels, dialogs, drawer): 1px border, `--shadow-sm`, `--r` radius; headings weights consistent; spacing between form groups even; focus ring consistent on buttons/links/controls; tables header/rows spacing.
- [ ] Walk every template under `packages/folio/templates/admin/**` and the admin templates of other packages (grep for `@folio` layouts / `extends "@folio/admin/_layout.twig"` in `packages/*/templates`) and fix any control or layout that still looks unstyled. List each screen in the check list.
- [ ] If a signed-in staff session is available in the browser, screenshot the key screens light/dark before/after; otherwise note "owner to check" in the list.
- [ ] Full suite; commit `style(folio): refresh pass and visual check list`.

### Task 6: Site extensions (BG Genius, holz-objekte.org)

**Files:** BG Genius `/Users/smyr/Sites/gbits/bggenius-preflow/app/Folio/BgGenius/**` (assets, templates); holz-objekte.org `/Users/smyr/Sites/gbits/holz-objekte.org` Folio extensions (find with grep for Folio extension classes/templates).

- [ ] In each repo, find extension markup with controls outside Folio's classes or with their own control CSS that now conflicts; adapt to Folio's classes/partials (e.g. the chips partial). No visual changes beyond aligning with Folio.
- [ ] Each repo: its own suite green (BG: `php vendor/bin/phpunit` ~600 tests 10-minute timeout + `node --test tests/js/*.test.js`; holz: `php vendor/bin/phpunit`), commit on the repo's current branch (BG: a branch `feat/folio-refresh-adapt`; holz: `main`, not pushed). If nothing needs changing in a repo, say so.
