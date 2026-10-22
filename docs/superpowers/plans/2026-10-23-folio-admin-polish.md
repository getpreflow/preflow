# Folio Admin Polish (0.22) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close five Folio gaps found while building the smart-stack.works approval screen, so the site needs no workarounds:
1. read-only fields show readable values;
2. record actions can stay out of the header;
3. list columns can show badges;
4. form panels can sit in a side column;
5. a generic tabs behaviour.

**Architecture:** All changes are additive and live in `packages/folio`. Defaults keep today's behaviour, except read-only display, which is a fix: it shows labels and formatted values where it used to show raw keys and JSON.

**Tech Stack:** PHP 8.4, Twig, Folio admin CSS/JS (no build step, nonce CSP), PHPUnit 11.

**Spec:** Members step 2 spec, `docs/superpowers/specs/2026-10-22-members-area-step2-design.md`:
- "Approval in Folio": the state badge column and the Decision panel;
- "Framework work" item 5: small items, each tested and released;
- the owner's rule that the admin follows Folio's look and is checked with screenshots.

The owner approved the mockup at `/Users/smyr/Sites/gbits/smart-stack.works/.superpowers/brainstorm/42306-1792694419/content/approval.html`: the Decision panel sits in a right-hand column, and the state shows as a pill.

Findings with file:line references: `.superpowers/sdd/2026-10-23-members-step2-site/task-5-review.md` (framework-gap section).

## Global Constraints

- Additive API only.
  - New optional parameters must default to today's behaviour.
  - Existing tests stay green: run `vendor/bin/phpunit` at the repo root; 1986 tests passed at the start of this plan.
- Use Folio's existing tokens and classes only:
  - `.folio-badge` with the tones `is-published`, `is-draft`, `is-changed` and `is-incomplete` (`packages/folio/resources/admin.css` ~558-577);
  - `.folio-segmented` (~1181-1207).
- New CSS goes into admin.css, and new JS into admin.js, with no inline scripts or styles. The strict nonce CSP stays as it is.
- Every visible change is checked with light and dark screenshots taken from the `examples/folio-demo` app or a test scaffold, saved in this plan's SDD workspace, and looked at.
- Release: version 0.22.0. The constraint `^0.22 || @dev` applies only where a package uses a 0.22 feature, which is folio only. Push and tag are gated by the user.

## Review Focus

1. **Read-only rich values.** A read-only `richtext` or `blocks` field must still be sanitised. A read-only `relation` must show labels, not ids. Test both.
2. **A date that doesn't parse.** A legacy ISO string with an offset (`2026-10-22T23:15:12+00:00`) shows formatted. Garbage shows as it is, escaped, and never throws.
3. **An action with `toolbar: false` is still callable.** It is still routed and permission-checked, and only missing from the header.
4. **A badge column holds text that needs escaping.** The label is escaped, and an unknown tone falls back to the plain badge.
5. **The side column on a narrow screen.** Below 1100 px it stacks under the form, not off-screen.

---

### Task 1: Read-only fields show display values; dates are formatted

**Files:**
- Modify:
  - `packages/folio/src/Http/AdminController.php` (`readonlyField()` ~973-988 and its call ~483-487; list cells ~128-134 if dates need it);
  - `packages/folio/src/Field/Types/DateFieldType.php`;
  - `packages/folio/src/Field/Types/AbstractScalarFieldType.php` (only if needed).
- Test:
  - `packages/folio/tests/Http/ReadonlyFieldDisplayTest.php` (new);
  - `packages/folio/tests/Field/DateFieldTypeTest.php` (extend or create).

**Interfaces:**
- Produces:
  - Read-only fields render through the field type's display form. That is the same "stripped renderFrontend" that list cells use, in escaped HTML, wrapped as today's readonly markup.
    - `select` → the option label;
    - `rbac_roles` / `relation` → the labels, comma-separated, or as `.folio-badge` chips where the type already renders chips;
    - `boolean` → Yes/No;
    - `richtext` → sanitised HTML;
    - empty → `—`.
  - `DateFieldType` gets display formatting:
    - `date` → `22 Oct 2026`;
    - with `"time": true` → `22 Oct 2026, 23:15`.
    - It accepts `Y-m-d`, `Y-m-d\TH:i`, and ATOM/ISO 8601 with an offset (shown in the app timezone `app.timezone`).
    - An unparsable value is shown escaped as it is.
    - The list cells of date fields use the same format.

- [ ] **Step 1: Write the failing tests:**
  - `test_readonly_select_shows_label`;
  - `test_readonly_roles_show_labels_not_json`: an rbac_roles value `["pending"]` → "Waiting for approval", or the role's label, or at least `pending`, without brackets or quotes;
  - `test_readonly_relation_shows_labels`;
  - `test_readonly_richtext_is_sanitised`;
  - `test_readonly_empty_shows_dash`;
  - `test_date_display_formats` (the three input shapes);
  - `test_date_display_garbage_is_escaped`;
  - `test_list_cell_formats_dates`.
- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter 'ReadonlyFieldDisplay|DateFieldType'`. Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run GREEN, then the full suite.** Take light and dark screenshots of an edit form with read-only select, roles, date and text fields.
- [ ] **Step 5: Commit.** `fix(folio): read-only fields show labels and formatted dates instead of raw values`

---

### Task 2: Panel-only record actions, badge columns, side panels, tabs

**Files:**
- Modify:
  - `packages/folio/src/Extension/RecordAction.php` (add `toolbar`);
  - `packages/folio/src/Extension/ListColumn.php` (doc);
  - `packages/folio/src/Extension/FormPanel.php` (position `side`);
  - `packages/folio/src/Http/AdminController.php` (action list ~648-657, list cells ~139-140, panels);
  - `packages/folio/templates/admin/form.twig` (header actions 5-8, panel slots 104-145);
  - `packages/folio/templates/admin/list.twig:24`;
  - `packages/folio/resources/admin.css`, `packages/folio/resources/admin.js` (~396-421);
  - `packages/folio/src/Customization/Customizations.php` (validation of the new values);
  - `packages/folio/README.md`.
- Create: `packages/folio/src/Extension/Badge.php`.
- Test: `packages/folio/tests/Extension/AdminPolishTest.php`.

**Interfaces:**
- Produces:
  - **`RecordAction(..., bool $toolbar = true)`.** With `false`, the action is not rendered in the form header but stays routed and permission-checked. It is meant for actions posted from a FormPanel's own form.
  - **`final class Badge`** with `public function __construct(public readonly string $label, public readonly string $tone = '')`, where `$tone` ∈ `'' | 'published' | 'draft' | 'changed' | 'incomplete'`.
    - A ListColumn method may return `string|Badge`.
    - A Badge renders as `<span class="folio-badge is-{tone}">{label}</span>` with the label escaped. An empty or unknown tone gives a plain `folio-badge`.
  - **`FormPanel(..., position: 'side')`.** Side panels render in a right-hand column next to the form fields: a sticky column about 340 px wide at ≥1100 px, stacked after the form below that.
    - `before` and `after` are unchanged.
    - An unknown position is a boot error, as unknown values are today, if Customizations validates them; otherwise add that validation.
  - **Tabs:** markup `<div data-folio-tabs>` containing a `.folio-segmented` with `<button data-folio-tab="key">` and sibling `<div data-folio-tabpanel="key">` elements.
    - admin.js wires them: ARIA `role="tablist"/"tab"/"tabpanel"`, `aria-selected`, arrow keys, and hidden panels.
    - Without JS every panel shows, stacked.
    - The first tab is active by default, or the one with `data-folio-tab-active`.

- [ ] **Step 1: Write the failing tests:**
  - `test_toolbar_false_action_not_in_header_but_routed`: the button is absent from `/folio/{type}/{id}/edit`, and a POST to `_action/{name}` still works and still needs the permission.
  - `test_list_column_badge_renders_with_tone_and_escapes`.
  - `test_list_column_unknown_tone_falls_back`.
  - `test_side_panel_renders_in_side_slot`.
  - `test_unknown_panel_position_is_rejected`.
  - `test_tabs_markup_documented`: a template-level test that admin.js contains the `data-folio-tabs` wiring, plus a render check of the README example.
- [ ] **Step 2: Run RED.** `vendor/bin/phpunit --filter AdminPolishTest`. Expected: FAIL.
- [ ] **Step 3: Implement.** Document all four in the README's extension section, next to RecordAction, ListColumn and FormPanel.
- [ ] **Step 4: Run GREEN, then the full suite.** Screenshots (light and dark, desktop and 900 px): a list with a badge column, and an edit form with a side panel that holds tabs.
- [ ] **Step 5: Commit.** `feat(folio): panel-only record actions, badge list columns, side panels and tabs`

---

### Task 3: Release prep

**Files:**
- Modify: `ROADMAP.md` (v0.22.0 entry) and `packages/folio/README.md` if anything is missing.
- Create: `docs/superpowers/plans/2026-10-23-release-0.22-checklist.md`, following the 0.21 checklist:
  1. merge;
  2. push main;
  3. tag `v0.22.0` with the message `v0.22.0: Folio read-only display, panel-only actions, badge columns, side panels, tabs`;
  4. Packagist check;
  5. the smart-stack.works feature branch moves to `^0.22 || @dev`.

  Every step is gated by the user.

No package constraints change: no other package uses these features.

- [ ] **Step 1:** Write the entries.
- [ ] **Step 2:** Run `vendor/bin/phpunit`. Expected: PASS.
- [ ] **Step 3: Commit.** `chore: prepare 0.22 (roadmap, release checklist)`
