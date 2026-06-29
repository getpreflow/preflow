# Folio Live Preview 3b — Surgical Per-field Updates — Design

**Date:** 2026-06-29
**Package:** `preflow/folio`
**Status:** Approved.
**Builds on:** `2026-06-27-folio-live-preview-3a-design.md` (3a, shipped). This is
the 3b cycle the roadmap (#3) and the 3a spec named: "surgical per-field fragment
swap — re-render only the changed field, no full reload." Additive on 3a.

## Goal

Make the live preview update only the field(s) that actually changed, in place,
instead of replacing the whole iframe document on each (debounced) keystroke.
This removes the flicker and preserves the preview's scroll position while the
author types. The 3a draft endpoint, the Craft-style overlay, the resizable
iframe, and the viewport presets are all unchanged.

## Decision (settled during brainstorming)

**Client-side diff + direct DOM patch — no postMessage.** The 3a preview iframe is
fed via `iframe.srcdoc`, which is **same-origin** with the admin page, so the
parent can read and write `iframe.contentDocument` directly. postMessage (the
roadmap's original wording) is unnecessary indirection here; it would only be
needed if the preview iframe ever pointed at a *cross-origin* real-site URL,
which is out of scope. The server keeps returning the full page HTML (the 3a
endpoint is untouched); all surgical logic lives in `admin.js`.

## Architecture

### Template marker — the only server-side change

`packages/folio/templates/frontend/page.twig` gains a `data-folio-field="{name}"`
attribute on each field's region (the `<h1>` title and each `.folio-field` div):

```twig
<h1 data-folio-field="title">{{ record.title }}</h1>
{% for name, html in rendered %}
    {% if name != 'title' %}<div class="folio-field folio-field-{{ name }}" data-folio-field="{{ name }}">{{ html|raw }}</div>{% endif %}
{% endfor %}
```

This is purely additive — it adds an attribute and changes no rendered text, so
the existing frontend/preview output and assertions are unaffected. It is the
**addressing contract**: a field's region is surgically updatable iff it carries
`data-folio-field`. Userland templates (custom `page.twig`, per-type templates)
opt in by adding the attribute; without it the preview correctly falls back to a
full reload. The marker is emitted by the package's default `page.twig` for the
common case.

### Client — `admin.js` `applyHtml(html)` replaces the blunt `srcdoc`

The preview `render()` (3a) still `fetch`-POSTs the form and receives the full
page HTML, with the existing latest-wins (`reqSeq`) guard. The only change: where
3a did `frame.srcdoc = html`, 3b calls `applyHtml(html)`:

- **Full render** — `frame.srcdoc = html` (and mark that a document now exists) —
  when ANY of:
  - it is the first render (no iframe document yet);
  - the incoming HTML contains zero `[data-folio-field]` regions (a userland
    template without the marker);
  - the incoming marker set differs from the current document's (a region name
    present in one but not the other, or the counts differ) — i.e. structure
    changed.
- **Surgical** — otherwise: `DOMParser().parseFromString(html, 'text/html')`,
  then for each incoming `[data-folio-field="X"]` whose `innerHTML` differs from
  the live iframe's region of the same name, write
  `frame.contentDocument.querySelector('[data-folio-field="X"]').innerHTML =
  incomingInnerHTML`. Regions that did not change are left untouched, so there is
  no flicker and the iframe scroll position is preserved.

Field names come from model JSON keys (simple identifiers), so the attribute-
value selector is safe to build by concatenation.

The matrix field is a single `data-folio-field="blocks"` region whose innerHTML
contains all rendered block records, so reordering/adding/removing blocks swaps
that one region as a unit — still surgical at field granularity, and the marker
set stays stable (one `blocks` region) so it does not trigger a full reload.

### Why the marker set is stable across edits

A content type's fields are fixed, so editing field values changes each region's
`innerHTML` but never the set of `data-folio-field` names. Surgical updates
therefore apply to essentially every in-session edit; the full-reload path is
reserved for the first render, marker-less userland templates, and genuine
structural divergence.

## New / affected files

- `packages/folio/templates/frontend/page.twig` — **modify** (add
  `data-folio-field` markers).
- `packages/folio/assets/admin.js` — **modify** (`render()` calls a new
  `applyHtml(html)` that diff-patches surgically with a full-reload fallback,
  replacing the direct `frame.srcdoc = html`).
- Tests: `packages/folio/tests/Integration/FolioAppTest.php` (modify — assert
  preview output carries the markers), `packages/folio/tests/Assets/AdminJsTest.php`
  (modify — static assertions for the diff/patch hooks).
- No new demo files; the existing demo `page` + default `page.twig` exercise it.

## Testing

**Integration — `FolioAppTest` (real Twig):** a `POST {prefix}/page/preview`
response contains `data-folio-field="title"` and `data-folio-field="body"` (the
markers the surgical client relies on). This is the meaningful server-observable
guarantee.

**Static — `admin.js` (`AdminJsTest`):** the diff/patch is present —
`data-folio-field`, `DOMParser` (parse the incoming HTML), per-region
`innerHTML` assignment, and the `srcdoc` full-reload fallback still present.

**Behavioral verification:** consistent with 3a and the matrix/drawer features,
the JS surgical behavior itself is verified by driving the demo in a browser
(the package ships no DOM test harness) — done at the end of the cycle: type into
a field and confirm only that field's region updates while the iframe scroll
position holds.

**Full suite:** `vendor/bin/phpunit` green (only the pre-existing PHPUnit
deprecations + 1 skip); folio + demo green under strict Twig.

## Out of scope

postMessage and cross-origin preview targets (only needed if preview ever renders
a real external site URL). Lowering the 400ms debounce. Sub-field diffing (e.g.
patching only the changed paragraph inside a richtext field — 3b swaps the whole
field region). Surgical updates for userland templates that omit the marker (they
get the correct full-reload behavior, documented as the opt-in contract).
