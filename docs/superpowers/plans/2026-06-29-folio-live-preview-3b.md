# Folio Live Preview 3b Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the live preview update only the field(s) that changed, in place, instead of replacing the whole iframe document each keystroke — removing flicker and preserving the iframe's scroll position.

**Architecture:** The frontend `page.twig` gains a `data-folio-field="{name}"` marker on each field region (the only server-side change). `admin.js`'s preview `render()` still fetches the full page HTML, but instead of `frame.srcdoc = html` it calls a new `applyHtml(html)` that parses the response and writes `innerHTML` only into the `[data-folio-field]` regions whose content actually changed — directly, because the srcdoc iframe is same-origin — falling back to a full `srcdoc` reload on first render, when markers are absent, or when the field structure differs.

**Tech Stack:** PHP 8.5, `preflow/folio`, Twig, vanilla JS (`admin.js`), PHPUnit 11. No build step; no new Composer dependency.

## Global Constraints

- **No build step; no new Composer dependency.** `admin.js` is hand-written vanilla JS.
- **No emojis.**
- **Additive on 3a.** The preview endpoint, the Craft-style overlay, the resizable iframe, viewport presets, latest-wins (`reqSeq`), debounce (~400ms), and form-reparent behavior are unchanged. The server still returns the full page HTML — NO new endpoint or response shape.
- **No postMessage.** The srcdoc iframe is same-origin; the parent patches `frame.contentDocument` directly.
- **Addressing contract:** a field region is surgically updatable iff it carries `data-folio-field="{name}"`. The package default `page.twig` emits it; templates without it fall back (correctly) to a full reload.
- **Full-reload fallback is non-negotiable** for correctness: first render (no iframe doc yet), zero `[data-folio-field]` markers in the response, or a differing marker set/structure → `frame.srcdoc = html`.
- **Test command:** `vendor/bin/phpunit <path>` from `/Users/smyr/Sites/gbits/flopp`. Integration/demo run under strict Twig (`APP_DEBUG=1` scoped to the path); the full suite runs with NO `APP_DEBUG` exported.

## File Structure

- `packages/folio/templates/frontend/page.twig` — **modify**: add `data-folio-field` markers (the addressing contract).
- `packages/folio/assets/admin.js` — **modify**: `render()` calls a new `applyHtml(html)` (surgical diff/patch + full-reload fallback) instead of the blunt `frame.srcdoc = html`.
- Tests: `packages/folio/tests/Integration/FolioAppTest.php` (modify — assert markers in preview output), `packages/folio/tests/Assets/AdminJsTest.php` (modify — static assertions for the diff/patch hooks).

---

### Task 1: Field markers in the frontend template

**Files:**
- Modify: `packages/folio/templates/frontend/page.twig`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Produces: the rendered frontend/preview page carries `data-folio-field="{name}"` on the `<h1>` title region and on each `.folio-field` div. Consumed by Task 2's `applyHtml`.

- [ ] **Step 1: Add the failing integration test**

In `packages/folio/tests/Integration/FolioAppTest.php`, add (near the other preview tests):

```php
    public function test_preview_output_carries_field_markers(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $body = (string) $app->handle($f->createServerRequest('POST', '/folio/page/preview')->withParsedBody([
            'title' => 'Marked', 'slug' => 'marked', 'body' => 'hello', 'status' => 'draft',
        ]))->getBody();
        $this->assertStringContainsString('data-folio-field="title"', $body);
        $this->assertStringContainsString('data-folio-field="body"', $body);
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter test_preview_output_carries_field_markers`
Expected: FAIL — `page.twig` does not emit `data-folio-field` yet.

- [ ] **Step 3: Add the markers to `page.twig`**

Replace `packages/folio/templates/frontend/page.twig` with:

```twig
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ record.title }}</title></head>
<body>
    <article>
        <h1 data-folio-field="title">{{ record.title }}</h1>
        {% for name, html in rendered %}
            {% if name != 'title' %}<div class="folio-field folio-field-{{ name }}" data-folio-field="{{ name }}">{{ html|raw }}</div>{% endif %}
        {% endfor %}
    </article>
</body>
</html>
```

- [ ] **Step 4: Run the integration suite**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — the new marker test plus all existing preview/frontend/matrix tests (the markers are additive; existing tests assert content strings, not the absence of the attribute).

- [ ] **Step 5: Commit**

```bash
git add packages/folio/templates/frontend/page.twig packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): mark frontend field regions with data-folio-field for surgical preview"
```

---

### Task 2: Surgical diff/patch in `admin.js`

**Files:**
- Modify: `packages/folio/assets/admin.js`
- Test: `packages/folio/tests/Assets/AdminJsTest.php` (modify)

**Interfaces:**
- Consumes: the `data-folio-field` markers (Task 1); the existing `render()` fetch + `reqSeq` latest-wins + `frame`/`hasDoc` state.
- Produces: `applyHtml(html)` — surgically writes `innerHTML` into changed `[data-folio-field]` regions of the same-origin `frame.contentDocument`, or `frame.srcdoc = html` (via `fullRender`) on first render / no markers / structural difference. `render()` calls `applyHtml(html)` in place of the direct `frame.srcdoc = html`.

- [ ] **Step 1: Add the failing static assertions**

In `packages/folio/tests/Assets/AdminJsTest.php`, add:

```php
    public function test_defines_surgical_preview_patch(): void
    {
        $js = $this->js();
        $this->assertStringContainsString('data-folio-field', $js); // addressing marker the client queries
        $this->assertStringContainsString('DOMParser', $js);        // parses the incoming HTML
        $this->assertStringContainsString('.innerHTML =', $js);     // surgical per-region write
        $this->assertStringContainsString('srcdoc', $js);           // full-reload fallback retained
    }
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php`
Expected: FAIL — `admin.js` has no `DOMParser`/surgical patch yet.

- [ ] **Step 3: Add `hasDoc` state**

In `packages/folio/assets/admin.js`, in `initPreview()`, add `hasDoc` to the preview state declaration. Replace:

```js
        var overlay = null, frame = null, anchor = null, parent = null, reqSeq = 0, timer = null, keyHandler = null;
```

with:

```js
        var overlay = null, frame = null, anchor = null, parent = null, reqSeq = 0, timer = null, keyHandler = null, hasDoc = false;
```

- [ ] **Step 4: Add `fullRender` + `applyHtml` and call it from `render()`**

In the same `initPreview()`, replace the `render()` function:

```js
        function render() {
            if (!frame) { return; }
            var seq = ++reqSeq;
            fetch(url, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (html != null && seq === reqSeq && frame) { frame.srcdoc = html; }
                })
                .catch(function () {});
        }
```

with:

```js
        function fullRender(html) {
            frame.srcdoc = html;
            hasDoc = true;
        }

        // Patch only the [data-folio-field] regions that changed, directly into the
        // same-origin srcdoc document. Fall back to a full srcdoc reload on the first
        // render, when the template has no markers, or when the field structure differs.
        function applyHtml(html) {
            try {
                var doc = hasDoc ? frame.contentDocument : null;
                if (!doc || !doc.body) { fullRender(html); return; }

                var incoming = new DOMParser().parseFromString(html, 'text/html');
                var incomingRegions = incoming.querySelectorAll('[data-folio-field]');
                var curRegions = doc.querySelectorAll('[data-folio-field]');
                if (incomingRegions.length === 0 || incomingRegions.length !== curRegions.length) {
                    fullRender(html);
                    return;
                }

                var patches = [];
                for (var i = 0; i < incomingRegions.length; i++) {
                    var name = incomingRegions[i].getAttribute('data-folio-field');
                    var cur = doc.querySelector('[data-folio-field="' + name + '"]');
                    if (!cur) { fullRender(html); return; } // a region went missing -> full reload
                    if (cur.innerHTML !== incomingRegions[i].innerHTML) {
                        patches.push([cur, incomingRegions[i].innerHTML]);
                    }
                }
                for (var j = 0; j < patches.length; j++) {
                    patches[j][0].innerHTML = patches[j][1];
                }
            } catch (e) {
                fullRender(html);
            }
        }

        function render() {
            if (!frame) { return; }
            var seq = ++reqSeq;
            fetch(url, { method: 'POST', body: new FormData(form), headers: { 'Accept': 'text/html' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (html != null && seq === reqSeq && frame) { applyHtml(html); }
                })
                .catch(function () {});
        }
```

- [ ] **Step 5: Reset `hasDoc` on close**

In the same `initPreview()`, in `closePreview()`, add `hasDoc = false;` to the final state reset so reopening starts with a full render. Replace:

```js
            overlay = null; frame = null; anchor = null; parent = null;
```

with:

```js
            overlay = null; frame = null; anchor = null; parent = null; hasDoc = false;
```

- [ ] **Step 6: Run the static + integration suites**

Run: `vendor/bin/phpunit packages/folio/tests/Assets/AdminJsTest.php` and
`APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — the new static assertions plus the existing overlay assertions; integration unchanged.

- [ ] **Step 7: Verify the JS parses**

Run: `node --check packages/folio/assets/admin.js`
Expected: no output (exit 0) — the file is valid JS.

- [ ] **Step 8: Commit**

```bash
git add packages/folio/assets/admin.js packages/folio/tests/Assets/AdminJsTest.php
git commit -m "feat(folio): surgical per-field preview patch (diff [data-folio-field], full-reload fallback)"
```

---

### Task 3: Full-suite verification

**Files:** none changed — verification only.

- [ ] **Step 1: Run the folio package suite under strict Twig**

Run: `APP_DEBUG=1 vendor/bin/phpunit packages/folio/tests`
Expected: PASS — all folio tests green.

- [ ] **Step 2: Run the demo smoke test**

Run: `APP_DEBUG=1 vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS — the demo app boots and serves `/folio`.

- [ ] **Step 3: Run the full repo suite**

Run: `vendor/bin/phpunit` (NO `APP_DEBUG` exported)
Expected: PASS — all suites green; only the pre-existing PHPUnit deprecations + 1 skip. No failures.

---

## Self-Review

**Spec coverage (3b):**
- `data-folio-field` markers on each field region (only server change) → Task 1; integration asserts they appear in preview output.
- Client diff + direct DOM patch of only changed regions; full-reload fallback (first render / no markers / structure differs) → Task 2 (`applyHtml`/`fullRender`), `hasDoc` first-render + close-reset.
- Endpoint/response unchanged; latest-wins + debounce preserved → Task 2 (`render()` keeps the fetch + `reqSeq`; only the apply step changed).
- No postMessage (same-origin direct `contentDocument` access) → Task 2.
- Matrix is one `data-folio-field="blocks"` region → handled by the generic per-region patch (no special-casing needed).
- Out of scope held: postMessage/cross-origin, debounce changes, sub-field diffing.

**Placeholder scan:** No `TBD`/`TODO`. Every code step shows full code; every modify step quotes the exact anchor.

**Type consistency:**
- `applyHtml(html)` and `fullRender(html)` defined in Task 2 Step 4; `render()` calls `applyHtml(html)` (same step); `hasDoc` declared in Step 3, set in `fullRender` (Step 4), reset in `closePreview` (Step 5).
- The marker string `data-folio-field` is identical between `page.twig` (Task 1 Step 3), the `applyHtml` selector (Task 2 Step 4), the integration assertion (Task 1 Step 1), and the static assertion (Task 2 Step 1).
- The fetch/`reqSeq`/`frame` guards in `render()` are preserved verbatim from 3a; only the success branch changes from `frame.srcdoc = html` to `applyHtml(html)`.
```
