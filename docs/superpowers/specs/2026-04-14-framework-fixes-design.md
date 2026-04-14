# Preflow Framework Fixes — Design Spec

**Date:** 2026-04-14
**Status:** Draft
**Goal:** Fix 7 priority issues discovered during the BGGenius migration stress test.

---

## Fix 1: HTMX-Aware Asset Delivery (#7) — CRITICAL

### Problem

`{% apply css %}` / `{% apply js %}` collect assets into `AssetCollector`, which only outputs them via `{{ head() }}` / `{{ assets() }}` on full page render. When a component re-renders via HTMX (through `ComponentEndpoint`), the collected CSS/JS is discarded — the fragment response contains only HTML.

### Design

**Where:** `ComponentEndpoint::handle()` in `packages/htmx/src/ComponentEndpoint.php`

After rendering the component HTML, check if new CSS/JS was collected during this render. If so, append `<style>` and `<script>` blocks to the response HTML. The AssetCollector already has `renderCss()`, `renderJsBody()`, etc. — use those.

**Tracking "new" assets:** The AssetCollector is a shared singleton. Assets from the initial page load are still in its registry. To avoid re-sending everything on every HTMX swap, snapshot the registry keys before rendering and diff after:

```php
// In ComponentEndpoint::handle()
$cssBefore = array_keys($assetCollector->getCssRegistry());
$jsBefore = array_keys($assetCollector->getJsRegistry());

$html = $this->renderer->renderResolved($component); // or render()

$cssAfter = array_keys($assetCollector->getCssRegistry());
$jsAfter = array_keys($assetCollector->getJsRegistry());

$newCssKeys = array_diff($cssAfter, $cssBefore);
$newJsKeys = array_diff($jsAfter, $jsBefore);
```

Then render only the new assets and append them to `$html`.

**AssetCollector changes needed:**
- Add `getCssRegistry(): array` — returns the registry keys (already hash-keyed)
- Add `getJsRegistries(): array` — returns keys from all JS registries
- Add `renderCssForKeys(array $keys): string` — renders only specified CSS entries
- Add `renderJsForKeys(array $keys): string` — renders only specified JS entries

Or simpler: add `snapshot(): array` and `renderNewSince(array $snapshot): string` that handles the diffing internally.

**Simpler alternative:** Since HTMX components use inline `<style>` tags anyway (as we discovered), the framework could just always append collected CSS/JS to fragment responses without diffing. Duplicates are harmless — browsers apply the same `<style>` block idempotently, and `AssetCollector` already deduplicates by hash so the same content won't be collected twice in a single render.

**Recommended approach:** The simpler alternative. In `ComponentEndpoint::handle()`, after rendering, call `$assetCollector->renderCss()` and `$assetCollector->renderJsBody()` and append to the HTML. The AssetCollector is reset or scoped per-request already (it's created fresh each request via Application boot), so collected assets are only from the current component render.

Wait — is the AssetCollector actually request-scoped? Let me verify. It's registered as a singleton in the container, created during `bootViewLayer()`. If it's a true singleton across the Application lifecycle, it persists between the initial page render and the HTMX endpoint render. But for HTMX requests, there IS no initial page render — the endpoint handles the request directly. So the AssetCollector starts empty for HTMX requests.

**Final design:** In `ComponentEndpoint::handle()`, after rendering the component, get the AssetCollector from the container and append `renderCss()` + `renderJsBody()` to the response HTML. This sends exactly the CSS/JS that the component (and its child components) registered during this render. CSP nonces are included automatically.

---

## Fix 2: Auto-Increment ID Support (#8) — CRITICAL

### Problem

`PdoDriver::save()` uses `INSERT OR REPLACE` (SQLite) / `INSERT ... ON DUPLICATE KEY UPDATE` (MySQL) which requires a valid ID. New models default to `id=0`, causing all inserts to overwrite each other.

### Design

**Where:** `PdoDriver::save()` in `packages/data/src/Driver/PdoDriver.php` and `DataManager::save()` in `packages/data/src/DataManager.php`

**PdoDriver changes:**
- Add `insert(string $type, array $data, string $idField = 'uuid'): string|int` — plain INSERT without the ID column (if ID is empty/zero), returns `lastInsertId()`
- Modify `save()` to detect when the ID value is "empty" (0, '', null) and delegate to `insert()` instead of upsert

**Detection logic in save():**
```php
$id = $data[$idField] ?? null;
$isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

if ($isEmpty) {
    // Remove ID from data, plain INSERT, return last insert ID
    unset($data[$idField]);
    return $this->insert($type, $data, $idField);
} else {
    // Existing upsert logic
}
```

**DataManager changes:**
- After `$driver->save()`, if the model's ID was empty, read back the new ID and set it on the model:
```php
$id = $data[$meta->idField] ?? null;
$isEmpty = $id === null || $id === '' || $id === 0 || $id === '0';

$driver->save($meta->table, $id, $data, $meta->idField);

if ($isEmpty) {
    // The driver returned the new ID — set it on the model
    $newId = $driver->lastInsertId();
    $model->{$meta->idField} = is_numeric($newId) ? (int) $newId : $newId;
}
```

**PdoDriver needs:** `lastInsertId(): string|int` — wraps `$this->pdo->lastInsertId()`

**StorageDriver interface:** Add `lastInsertId(): string|int` to the interface. `JsonFileDriver` returns the ID it was given (no auto-increment for file storage).

**Dialect changes:**
- Add `insertSql(string $table, array $columns): string` — plain `INSERT INTO table (cols) VALUES (placeholders)` without the upsert conflict clause.

---

## Fix 3: Catch-All Attribute Route Params (#10) — CRITICAL

### Problem

`AttributeRouteScanner::buildRegex()` compiles `{param}` to `(?P<param>[^/]+)` — no slash matching. Can't capture nested paths like `games/slug/cover.jpg`.

### Design

**Where:** `AttributeRouteScanner::buildRegex()` in `packages/routing/src/AttributeRouteScanner.php`

**Convention:** `{param...}` (trailing ellipsis) indicates a catch-all parameter.

**Changes:**
```php
private function buildRegex(string $pattern, array &$paramNames): string
{
    $regex = preg_replace_callback('/\{(\w+)(\.{3})?\}/', function ($m) use (&$paramNames) {
        $paramNames[] = $m[1];
        $isCatchAll = isset($m[2]) && $m[2] === '...';
        return $isCatchAll ? '(?P<' . $m[1] . '>.+)' : '(?P<' . $m[1] . '>[^/]+)';
    }, $pattern);

    return '#^' . $regex . '$#';
}
```

Also need to set `isCatchAll` on the `RouteEntry` when a catch-all param is detected. Check if `RouteEntry` has this field (it does — from the file-based scanner).

---

## Fix 4: Request Context in Templates (#9) — CRITICAL

### Problem

Page templates only receive `route` (params object). No access to the current URL path.

### Design

**Where:** `Application::ensureComponentRenderer()` in `packages/core/src/Application.php` (the component renderer closure)

**Change:** Pass the request alongside route params:

```php
$html = $engine->render($templatePath, [
    'route' => (object) $route->parameters,
    'request' => (object) [
        'path' => $request->getUri()->getPath(),
        'method' => $request->getMethod(),
        'isHtmx' => $request->getHeaderLine('HX-Request') === 'true',
        'query' => $request->getQueryParams(),
    ],
]);
```

Templates can then use `request.path`, `request.method`, `request.isHtmx`, `request.query`.

---

## Fix 5: Raw CSRF Token Value (#11) — CRITICAL

### Problem

`csrf_token()` returns `<input type="hidden" ...>` instead of the raw token value.

### Design

**Where:** `AuthExtensionProvider` in `packages/auth/src/AuthExtensionProvider.php`

**Changes:**
- Rename current `csrf_token` function to `csrf_field` (returns the HTML input)
- Add new `csrf_token` function that returns just the raw token value string
- Keep `csrf_field` as the function for forms
- `csrf_token` is what you use in meta tags, JS variables, fetch headers

```php
new TemplateFunctionDefinition(
    name: 'csrf_token',
    callable: fn () => $this->csrfValue(),
    isSafe: false, // raw string, should be escaped in HTML attributes
),
new TemplateFunctionDefinition(
    name: 'csrf_field',
    callable: fn () => $this->csrfField(),
    isSafe: true, // pre-escaped HTML
),
```

**Backward compatibility:** This is a breaking change for anyone using `{{ csrf_token() | raw }}` in forms (expecting HTML input). They need to switch to `{{ csrf_field() | raw }}`. Since v0.x, acceptable. Document in changelog.

---

## Fix 6: Script Execution Ordering (#12) — HIGH

### Problem

When `{% apply js %}` registers JS via AssetCollector, it's output at `{{ assets() }}` at the end of `<body>`. But for inline `<script>` tags in component templates (workaround for Fix 1), the script appears before the component's HTML in the document and runs before the DOM exists on full page load.

### Design

**Where:** `PreflowExtension::registerJs()` in `packages/twig/src/PreflowExtension.php`

**With Fix 1 implemented**, `{% apply js %}` will work for HTMX swaps (the endpoint appends collected JS to fragment responses). So components can go back to using `{% apply js %}` instead of raw `<script>` tags.

For full page loads, `{{ assets() }}` outputs JS at the end of `<body>` — after all component HTML. This already works correctly.

**The remaining issue:** Component templates that mix `{% apply js %}` blocks with HTML. The `{% apply js %}` block captures JS and sends it to AssetCollector (returning empty string). `{{ assets() }}` outputs it later. On full page load, the JS runs at end of `<body>` when all DOM exists. On HTMX swaps (with Fix 1), JS is appended after the component HTML.

**Fix 1 makes this largely moot.** The DOMContentLoaded guard is only needed for raw `<script>` tags, which won't be needed once Fix 1 is in place. However, for safety, the framework should wrap JS output from `renderJsBody()` in a guard when it detects an HTMX context:

```php
// In the HTMX fragment JS output (Fix 1)
$js = $assetCollector->renderJsBody();
// Already in a <script> tag — browser executes after DOM insertion for HTMX
// No guard needed — HTMX injects the fragment first, then runs scripts
```

Actually, HTMX processes `<script>` tags in swapped content by executing them after the DOM swap. So this is already handled by HTMX's design. **No additional framework change needed beyond Fix 1.**

Mark this as resolved by Fix 1.

---

## Fix 7: JSON Body Parsing (#13) — HIGH

### Problem

`$request->getParsedBody()` only handles form-encoded and multipart data. JSON bodies (`Content-Type: application/json`) return null.

### Design

**Where:** New middleware `JsonBodyMiddleware` in `packages/core/src/Http/JsonBodyMiddleware.php`

**Implementation:**
```php
final class JsonBodyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        
        if (str_contains($contentType, 'application/json')) {
            $body = (string) $request->getBody();
            if ($body !== '') {
                $parsed = json_decode($body, true);
                if (is_array($parsed)) {
                    $request = $request->withParsedBody($parsed);
                }
            }
        }
        
        return $handler->handle($request);
    }
}
```

**Registration:** Auto-register in `Application::boot()` as an early middleware (before CSRF, auth, etc.) so all downstream handlers get parsed JSON via `$request->getParsedBody()`.

---

## Test Strategy

Each fix should have targeted unit tests:

1. **Fix 1:** Test that ComponentEndpoint response includes CSS/JS collected during render
2. **Fix 2:** Test insert with `id=0` returns auto-increment ID, subsequent inserts get unique IDs
3. **Fix 3:** Test `{path...}` route matches nested paths with slashes
4. **Fix 4:** Test template receives `request.path`, `request.method`, `request.isHtmx`
5. **Fix 5:** Test `csrf_token()` returns raw string, `csrf_field()` returns HTML input
6. **Fix 6:** Resolved by Fix 1 — no separate test needed
7. **Fix 7:** Test JSON body parsed by middleware, available via `getParsedBody()`

All existing 540+ tests must continue to pass.
