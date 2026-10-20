# Folio 4a Visual Editor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Editing a routed record happens next to its live preview. Clicking the page edits it, focusing a field shows it on the page, blocks reorder by drag or keyboard, and removing one can be undone.

**Architecture:**
- **Split view:** server-rendered in `form.twig` (form pane | resizer | preview stage), replacing today's full-screen overlay. `admin.js` drives it.
- **Preview-only editor files:** `preview-editor.css`/`.js`, served from Folio's `_assets`, so `'self'` is enough and no nonce is needed. `PreviewController` injects them only into admin preview renders, never into public pages or share links.
- **Messaging:** the two documents talk over `postMessage` with an origin check, and blocks join on `_uid`.
- **Preview mode inherits:** `BlockRenderer` keeps preview mode for nested renders, so `folio_editable()` and nested blocks need no flag threading.

**Tech Stack:** PHP 8.4, Twig, vanilla JS (no build step), PHPUnit 11.

**Spec:** `docs/superpowers/specs/2026-10-19-folio-editor-experience-design.md`, section 4a (approved 2026-10-19).

## Global Constraints

- **Server-rendered admin:** Twig plus htmx fragments. JS is small vanilla code in `admin.js` (and the preview-only file), with no SPA, no client state store and no build step.
- **The preview is the real site:** editor markers and editor files exist only in admin preview renders.
- **Strict CSP and zero external requests:**
  - no inline handlers or `style=""` in templates, as checked by `CspGuardTest`;
  - JS sets styles through properties only;
  - assets come from `'self'`.
- **Storage:** `localStorage` holds only admin conveniences (pane width, view mode, focus mode), wrapped in try/catch.
- **Out of scope:**
  - dragging across fields or levels;
  - inline (contenteditable) editing.
- **Keep these hooks:**
  - `data-folio-preview`, `data-preview-url`, `folio-preview-frame`, `'768px'`, `'375px'`;
  - `[data-block-card]` / `data-block-uid` / `[_order][]`;
  - `folio-form-actions`, `id="folio-theme-toggle"`.

## Review Focus

1. A preview where blocks carry no markers (a site template without `folio_blocks(…, preview)`, or a type with no blocks). Click-to-edit then degrades to nothing and must not throw. Field regions still work.
2. Clicking a link or button inside the preview must select the block, not navigate the iframe away from the preview.
3. Block-level patching after an edit: a changed block is replaced in place and scroll stays put. Any change outside blocks falls back to a full reload that restores the scroll position.
4. Keyboard reorder, then Escape: the card returns to where it was lifted, and nothing is saved.
5. Undoing a remove after autosave already saved the removal: the block comes back with its fields and the next autosave stores it again.

---

### Task 1: Preview mode inherits; `folio_editable()`; block label on markers

**Files:**
- Modify: `packages/folio/src/Block/BlockRenderer.php`, `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Block/BlockRendererTest.php` (create if absent), `packages/folio/tests/Integration/FolioAppTest.php`

**Interfaces:**
- Produces:
  - `BlockRenderer::inPreview(): bool`: true while a preview render runs.
  - Preview wrappers become `<div class="folio-block" data-folio-block="{uid}" data-folio-label="{definition label}">`.
  - Twig `folio_editable(string $field): string` (safe). It returns ` data-folio-edit="{field}"` while `inPreview()` is true and `''` otherwise.
- The attribute is `data-folio-edit`, not the spec's `data-folio-field`. That name already marks top-level patch regions, and reusing it would break region counting.

- [ ] **Step 1:** Tests:
  - `test_nested_render_inherits_preview`: a parent block whose template calls `folio_blocks(items)` without a flag, rendered with `preview=true`, gives the child a `data-folio-block` wrapper.
  - `test_folio_editable_only_in_preview`: a fixture block template containing `<h2{{ folio_editable('headline') }}>` emits `data-folio-edit="headline"` in a preview render, and the public render has no `data-folio-edit`.
  - `test_preview_wrapper_carries_block_label`: `data-folio-label="Hero"`.
- [ ] **Step 2:** Run them. Expected: FAIL (no inheritance, unknown function, no label).
- [ ] **Step 3:** Implement:
  - a depth counter in `BlockRenderer`, incremented around a preview `render()`/`renderBlock()`;
  - nested calls with `preview=false` use `inPreview()`;
  - register `folio_editable`;
  - add the label attribute (htmlspecialchars).
- [ ] **Step 4:** Run the Folio suite. Expected: PASS, including `test_preview_markers_only_in_preview`.
- [ ] **Step 5:** Commit `feat(folio): preview mode reaches nested blocks, folio_editable() field targets`.

### Task 2: Preview-only editor files

**Files:**
- Create: `packages/folio/assets/preview-editor.css`, `packages/folio/assets/preview-editor.js`
- Modify:
  - `packages/folio/src/Http/PreviewController.php`
  - `FolioServiceProvider::assetMap` (serve both files)
- Test:
  - `FolioAppTest` (preview contains both, public and share-link renders don't)
  - `tests/Assets/PreviewEditorJsTest.php` (string checks in the AdminJsTest style)

**Interfaces:**
- Produces messages. All have `source: 'folio-preview'` (preview → admin) or `source: 'folio-editor'` (admin → preview), are sent with `window.location.origin` as target, and are checked on receipt with `e.origin === location.origin`:
  - preview → admin: `{type:'select', uid:string|null, field:string|null}` and `{type:'ready'}`;
  - admin → preview: `{type:'highlight', uid:string|null, field:string|null, scroll:boolean}`.
- Injection: `PreviewController` inserts `<link rel="stylesheet" href="{folio_asset preview-editor.css}">` before `</head>` and `<script src="{folio_asset preview-editor.js}" defer></script>` before `</body>`. It does this only when it has an admin-session preview, which is always the case for this controller. FrontendController's share-link path does not inject.
- Preview script behaviour:
  - **Hover:** hovering a `[data-folio-block]` outlines it and shows a small label badge (from `data-folio-label`).
  - **Click:** a click inside a block (capture phase, `preventDefault` on links and buttons) posts `select` with the innermost block's uid and the innermost `[data-folio-edit]` field. A click on a top-level `[data-folio-field]` region posts `select` with `uid:null` and that field.
  - **Highlight:** `highlight` sets `is-folio-active` on the matching element and, when `scroll` is set and the element is off-screen, calls `scrollIntoView({block:'center', behavior:'smooth'})`.

- [ ] **Step 1:** Tests:
  - `test_preview_injects_editor_assets`: the preview body contains `/folio/_assets/preview-editor.js?v=` and `preview-editor.css`.
  - `test_public_and_share_renders_have_no_editor_assets`.
  - JS string checks: `'folio-preview'`, `e.origin`, `preventDefault`, `data-folio-edit`, `scrollIntoView`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement. Inject by string insertion after `injectInto`, using the same `folio_asset` resolver (factor out a small `AssetUrls` callable if it isn't reachable from the controller).
- [ ] **Step 4:** Run the Folio suite plus `CspGuardTest`. Expected: PASS.
- [ ] **Step 5:** Commit `feat(folio): preview-only editor script and styles`.

### Task 3: Split view

**Files:**
- Modify:
  - `templates/admin/form.twig` (split markup when `previewable`)
  - `assets/admin.js` (replace the overlay with `initSplit`; render, applyHtml and fullRender move with it)
  - `assets/admin.css`
- Test: `FolioAppTest`, `AdminJsTest`

**Interfaces:**
- Markup, for previewable types only:
  ```
  .folio-split[data-folio-split]
    > .folio-split-form (the existing .folio-form)
    > .folio-resizer[data-folio-resizer][role=separator][aria-orientation=vertical][tabindex=0][aria-valuenow]
    > .folio-split-stage[data-folio-stage][data-preview-url]
      > bar (viewport segmented control, focus-mode toggle)
      > iframe.folio-preview-frame
  ```
- The topbar holds a view-mode segmented control, `[data-folio-view]` with the values `form | split | preview`, and keeps the `data-folio-preview` attribute.
- `localStorage` keys: `folio-split-width` (form pane px, clamped 320 to 60% of the shell), `folio-split-mode`, `folio-focus-mode`.
- The default mode is `split` at ≥ 1100px viewport and `form` below.
- Resizer: pointer drag (pointer capture), plus ArrowLeft/ArrowRight in 24px steps and Home/End. The width is applied through `style.setProperty('--folio-form-w', …)`, which is not an inline style attribute in the template.

- [ ] **Step 1:** Tests:
  - `test_edit_form_renders_split_view_for_routed_type`: `data-folio-split`, `data-folio-stage`, `data-preview-url="/folio/page/preview"`, `data-folio-resizer`.
  - The existing `test_edit_form_no_preview_button_for_non_frontend_type` must still pass (no split).
  - JS: `'folio-split-width'`, `'data-folio-resizer'`, `setPointerCapture`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement:
  - The preview renders on load in `split` and `preview` modes, and on the first switch into them otherwise.
  - Remove the overlay and the form-moving code.
  - The sticky publishing bar stays inside the form pane.
- [ ] **Step 4:** Folio suite PASS. Then check in the browser:
  - resize, reload (the width persists);
  - all three modes;
  - no horizontal scroll at a 340px form pane.
- [ ] **Step 5:** Commit `feat(folio): split-view editor with resizable, remembered panes`.

### Task 4: Click-to-edit and two-way highlight

**Files:** `assets/admin.js`, `assets/admin.css`; test `AdminJsTest`.

**Interfaces:**
- Consumes the Task 2 messages.
- On `select`:
  1. Find `#block-{uid}`.
  2. Open it and every ancestor card.
  3. If focus mode is on, collapse its open sibling cards.
  4. Scroll the form pane so the card sits near the top.
  5. Focus the field input when one is given, otherwise the first focusable input in the card body.
  6. The card flashes `is-located`.
- The field input is the element in the card body whose `name` ends with `[{uid}][{field}]` (or `[{uid}][{field}][{locale}]`). For `uid:null` it is the input named `{field}`.
- On `focusin`/click in the form: post `highlight` for the closest card's uid, or for the field name when no card contains the target, with `scroll:true`, at most once every 150ms.
- On preview `ready`: re-send the current highlight.

- [ ] **Step 1:** JS string checks: `"type === 'select'"`, `'is-located'`, `'folio-focus-mode'`, `'focusin'`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Check in the browser on smeyer.me content:
  - a click on the hero headline opens Hero and focuses Headline (`folio_editable` is added to the site in Task 8);
  - a click on a nested book block opens Proof → Book feature;
  - focusing a card outlines and scrolls the preview;
  - links in the preview don't navigate.
- [ ] **Step 5:** Commit `feat(folio): click the preview to edit, focus the form to locate`.

### Task 5: Block-level patching and scroll keeping

**Files:** `assets/admin.js`; test `AdminJsTest`.

**Interfaces:**
- `applyHtml` order:
  1. **Field regions:** as today, when the region sets match.
  2. **Blocks:** otherwise, if the top-level `[data-folio-block]` uids (those not inside another block) appear in the same order in both documents, and the body with every top-level block replaced by an empty placeholder is identical in both, replace the changed blocks' `outerHTML` and post `ready`.
  3. **Fallback:** otherwise do a full render. Before navigating, save `frame.contentWindow.scrollY`, and restore it on the next `load`.

- [ ] **Step 1:** JS string checks: `'outerHTML'`, `'scrollY'`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Check in the browser:
  - typing in a block updates only that block, without a flash;
  - changing the page title falls back to a full reload with the scroll kept;
  - reordering blocks falls back to a full reload with the scroll kept.
- [ ] **Step 5:** Commit `feat(folio): preview patches changed blocks in place`.

### Task 6: Drag and keyboard reorder

**Files:**
- `templates/admin/blocks/card.twig` (a grip handle `button[data-block-handle][aria-roledescription="drag handle"]` with the `ui-grip` icon, added to the sprite)
- `templates/admin/blocks/field.twig` (`<div class="folio-sr" aria-live="assertive" data-blocks-live></div>`)
- `assets/admin.js`, `assets/admin.css`, `assets/icons.svg`
- Tests: `FolioAppTest` (the handle and live region render), `AdminJsTest`

**Interfaces:**
- **Pointer:** `pointerdown` on the handle sets `draggable=true` on its card. `dragstart`/`dragover`/`drop` work only among cards with the same `parentNode`. The drop indicator is a class on the target card (`is-drop-before`/`is-drop-after`). `dragend` clears it and calls `notifyChange`.
- **Keyboard:**
  - With the handle focused, Space or Enter lifts the card (`is-lifted`, announcing "Lifted {label}, position n of m").
  - ArrowUp/ArrowDown move it through `siblingCard`, announcing the new position.
  - Space or Enter drops it ("Dropped"), then calls `notifyChange`.
  - Escape restores the original next-sibling and announces "Cancelled".
- Order is still carried by each card's own `[_order][]` input, so nothing is renumbered.

- [ ] **Step 1:** Tests:
  - `test_block_card_has_drag_handle_and_live_region`;
  - JS: `'dragstart'`, `'data-block-handle'`, `'aria-live'` or `'data-blocks-live'`, `"'Escape'"`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Check in the browser:
  - drag Proof above World view and save: the order persists;
  - keyboard lift, move, drop: persists;
  - keyboard lift, move, Escape: unchanged, nothing saved;
  - dragging into a nested list is refused.
- [ ] **Step 5:** Commit `feat(folio): drag and keyboard reorder for blocks`.

### Task 7: Undo remove, navigation guard

**Files:**
- `assets/admin.js`, `assets/admin.css`
- `templates/admin/_layout.twig` (a `<div class="folio-toasts" aria-live="polite" data-folio-toasts></div>` before `</body>`)
- Tests: `AdminJsTest`, `FolioAppTest` (the toast region renders)

**Interfaces:**
- **Remove:**
  - The card is detached, and a reference to it, its parent and its next sibling is kept.
  - A toast says "{label} removed", with an Undo button, for 8 seconds.
  - Undo reinserts the card at that spot (or appends it when the sibling is gone) and calls `notifyChange`.
  - A newer removal replaces the toast and finalises the older one.
- **Guard:** on forms without `hx-post` autosave, the first `input`/`change` marks the form dirty, `beforeunload` asks while it's dirty, and `submit` clears the flag.

- [ ] **Step 1:** Tests:
  - `test_layout_has_toast_region`;
  - JS: `'Undo'`, `'beforeunload'`.
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Check in the browser:
  - remove, Undo: restored in place;
  - remove, wait for autosave, Undo: the next autosave stores it again;
  - an edit on a non-publishing type, then leaving the page: prompted.
- [ ] **Step 5:** Commit `feat(folio): undo block removal, unsaved-changes guard`.

### Task 8: Release and smeyer.me field targets

**Files:**
- Framework: tag `v0.15.0` after the merge to main.
- smeyer.me: `app/Components/{Hero,WorldView,Proof,BookFeature,PreflowFeature,ZeroApps,ClubNote,NowList,WorkWithMe}/*.twig`: add `{{ folio_editable('…') }}` to their main headings and texts.
- Tests: smeyer.me's suite, and a new test `test_public_page_has_no_editor_markers` (no `data-folio-edit`, `data-folio-block` or `preview-editor` on `/`).

- [ ] **Step 1:** Write the smeyer.me test and run it. Expected: PASS, which is the guard. Then add the `folio_editable` calls; the test must still pass.
- [ ] **Step 2:**
  1. Run the full framework suite, then merge, push and tag `v0.15.0`.
  2. Wait for the split and Packagist.
  3. Bump the smeyer.me constraint to `^0.15 || @dev`, then `composer update --lock`, its suite, commit, push and `dep deploy`.
- [ ] **Step 3:** Smoke test live:
  - public pages carry no editor markers or files;
  - `/folio/page/home/edit` opens in split view.
