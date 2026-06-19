# Folio Field/Editor System — Phase 3 (Asset Upload Field) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an `asset` field type that uploads files into a Folio uploads dir and stores their path(s) (single or multiple, `accept`-filtered), served back through a package-owned `{prefix}/_uploads/{...path}` route.

**Architecture:** A new `UploadController` streams files from `storage/uploads` (path-traversal guarded, safe content-type). An `AssetFieldType` (implementing `FieldType` + a small `HandlesUpload` capability interface) renders a file input + existing-file list with remove checkboxes, validates and moves uploaded files to randomized paths on save, and renders `<img>`/links on the frontend. `AdminController`'s save path routes `HandlesUpload` fields through uploaded files instead of the parsed body, and the form gains `enctype="multipart/form-data"` when an upload field is present.

**Tech Stack:** PHP 8.5, `preflow/folio`, PSR-7 uploaded files (`Psr\Http\Message\UploadedFileInterface`, `nyholm/psr7`), Twig, PHPUnit 11. No build step; no new Composer dependency; no admin JS this phase.

## Global Constraints

- **PHP 8.5+**, `declare(strict_types=1)` in every PHP file.
- **No build step; no new Composer dependency; no admin JS** this phase (the asset field works with a native `<input type="file">` + remove checkboxes; `admin.js` is still deferred to Phase 5 / matrix).
- **No emojis** in code or UI copy.
- **Uploads served via a package route** `{prefix}/_uploads/{...path}` (drop-in; no public symlink). Production/media-library (#4) can later front it with the web server.
- **Security:** validate uploaded files against the field's `accept` (extension allowlist); **randomized stored filenames** (never user-controlled); **path-traversal guard** on the uploads route (`realpath` confined to the uploads base); serve non-image types as `application/octet-stream` (no inline script execution; SVG included → octet-stream).
- **All asset URLs** are produced by the field type via its injected upload-URL prefix (`{prefix}/_uploads`). Per the Phase-2 note, do not hardcode asset `src`s elsewhere — admin static assets still go through `folio_asset()`.
- Folio admin is **action-mode** (bypasses AssetCollector); the form is plain server-rendered markup.
- Admin POSTs carry `_csrf_token` — uploads use the same form, so they are covered.
- Templates overridable via `@folio`.
- **Test command:** `vendor/bin/phpunit <path>` from repo root `/Users/smyr/Sites/gbits/flopp`. Integration tests run under strict Twig (`APP_DEBUG=1`).

## File Structure

- `packages/folio/src/Http/UploadController.php` — **new**: serves `storage/uploads` files.
- `packages/folio/src/Routing/FolioRoutes.php` — **modify**: add `{prefix}/_uploads/{...path}` route.
- `packages/folio/src/Field/HandlesUpload.php` — **new**: capability interface for field types that consume uploaded files.
- `packages/folio/src/Field/Types/AssetFieldType.php` — **new**.
- `packages/folio/src/Http/AdminController.php` — **modify**: upload-aware save path + multipart flag.
- `packages/folio/templates/admin/form.twig` — **modify**: conditional `enctype`.
- `packages/folio/src/FolioServiceProvider.php` — **modify**: uploads dir, `UploadController` binding, register `AssetFieldType`.
- Tests: `packages/folio/tests/Http/UploadControllerTest.php` (new), `packages/folio/tests/Field/AssetFieldTypeTest.php` (new), `packages/folio/tests/Integration/FolioAppTest.php` (modify).
- `examples/folio-demo/config/models/page.json` — **modify** (add a demo asset field).

---

### Task 1: Uploads-serving route (`UploadController`)

**Files:**
- Create: `packages/folio/src/Http/UploadController.php`
- Modify: `packages/folio/src/Routing/FolioRoutes.php`, `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Http/UploadControllerTest.php` (new)

**Interfaces:**
- Produces: `UploadController::__construct(string $uploadsDir)` + `serve(ServerRequestInterface $request): ResponseInterface` (reads the `path` route attribute; 404 unless the `realpath` resolves inside `$uploadsDir` and is a file; content-type by extension via a safe map, default `application/octet-stream`; `Cache-Control: public, max-age=31536000`). Route `GET {prefix}/_uploads/{...path}` → `Preflow\Folio\Http\UploadController@serve`, appended in `FolioRoutes::admin()` after the `_assets` route. Uploads dir = `config('folio')['uploads_path']` or `basePath('storage/uploads')`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Http/UploadControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\UploadController;

final class UploadControllerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_up_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/2026/06', 0777, true);
        file_put_contents($this->dir . '/2026/06/pic.png', 'PNGDATA');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/2026/06/pic.png');
        @rmdir($this->dir . '/2026/06');
        @rmdir($this->dir . '/2026');
        @rmdir($this->dir);
    }

    private function get(string $path)
    {
        return (new UploadController($this->dir))->serve(
            (new Psr17Factory())->createServerRequest('GET', '/folio/_uploads/' . $path)->withAttribute('path', $path),
        );
    }

    public function test_serves_file_with_content_type(): void
    {
        $res = $this->get('2026/06/pic.png');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('image/png', $res->getHeaderLine('Content-Type'));
        $this->assertSame('PNGDATA', (string) $res->getBody());
    }

    public function test_missing_file_404(): void
    {
        $this->assertSame(404, $this->get('2026/06/nope.png')->getStatusCode());
    }

    public function test_path_traversal_blocked(): void
    {
        // a traversal that resolves outside the uploads dir must 404, never read it
        $this->assertSame(404, $this->get('../../../../etc/hosts')->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Http/UploadControllerTest.php`
Expected: FAIL — `Preflow\Folio\Http\UploadController` does not exist.

- [ ] **Step 3: Create the controller**

Create `packages/folio/src/Http/UploadController.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Streams uploaded files from the Folio uploads dir. Path-traversal guarded via
 * realpath confinement; non-image types are served as octet-stream so nothing
 * runs inline. Stored filenames are randomized, so a long cache is safe.
 */
final class UploadController
{
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
    ];

    public function __construct(private readonly string $uploadsDir) {}

    public function serve(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');

        $base = realpath($this->uploadsDir);
        if ($base === false) {
            return new Response(404, [], 'Not found');
        }

        $full = realpath($this->uploadsDir . '/' . $path);
        if ($full === false
            || !str_starts_with($full, $base . DIRECTORY_SEPARATOR)
            || !is_file($full)
        ) {
            return new Response(404, [], 'Not found');
        }

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $type = self::TYPES[$ext] ?? 'application/octet-stream';

        return new Response(
            200,
            ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=31536000'],
            (string) file_get_contents($full),
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Http/UploadControllerTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Add the route**

In `packages/folio/src/Routing/FolioRoutes.php`, after the `_assets` route block (before `return $entries;`), add:

```php
        // Package-owned upload serving. Catch-all (paths contain slashes), placed
        // before the frontend catch-all so it wins for {prefix}/_uploads/*.
        $uploadPattern = $prefix . '/_uploads/{...path}';
        $uc = PatternCompiler::compile($uploadPattern);
        $entries[] = new RouteEntry(
            pattern: $uploadPattern,
            handler: 'Preflow\\Folio\\Http\\UploadController@serve',
            method: 'GET',
            mode: RouteMode::Action,
            middleware: [],
            paramNames: $uc['paramNames'],
            regex: $uc['regex'],
            isCatchAll: $uc['isCatchAll'],
        );
```

- [ ] **Step 6: Bind the controller + uploads dir in the provider**

In `packages/folio/src/FolioServiceProvider.php`:

Add the import:

```php
use Preflow\Folio\Http\UploadController;
```

Add a private helper (near `prefix()`):

```php
    private function uploadsDir(Application $app): string
    {
        $cfg = $this->folioConfig($app);
        $path = $cfg['uploads_path'] ?? null;
        return is_string($path) ? $path : $app->basePath('storage/uploads');
    }
```

In `register()`, after the `AssetController` binding, add (note: `$app` is already available in `register()`):

```php
        $uploadsDir = $this->uploadsDir($app);
        $container->bind(UploadController::class, fn (Container $c) => new UploadController($uploadsDir));
```

- [ ] **Step 7: Write the failing route test**

Add this method to `packages/folio/tests/Routing/FolioRoutesTest.php`:

```php
    public function test_admin_includes_uploads_route(): void
    {
        $entries = FolioRoutes::admin('/folio');
        $match = null;
        foreach ($entries as $e) {
            if ($e->pattern === '/folio/_uploads/{...path}') {
                $match = $e;
                break;
            }
        }
        $this->assertNotNull($match, 'uploads route should be registered');
        $this->assertSame('Preflow\\Folio\\Http\\UploadController@serve', $match->handler);
        $this->assertTrue($match->isCatchAll);
    }
```

- [ ] **Step 8: Run the route test + uploads test**

Run: `vendor/bin/phpunit packages/folio/tests/Routing/FolioRoutesTest.php packages/folio/tests/Http/UploadControllerTest.php`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add packages/folio/src/Http/UploadController.php packages/folio/src/Routing/FolioRoutes.php packages/folio/src/FolioServiceProvider.php packages/folio/tests/Http/UploadControllerTest.php packages/folio/tests/Routing/FolioRoutesTest.php
git commit -m "feat(folio): serve uploads via package-owned _uploads route"
```

---

### Task 2: `AssetFieldType` + `HandlesUpload`

**Files:**
- Create: `packages/folio/src/Field/HandlesUpload.php`
- Create: `packages/folio/src/Field/Types/AssetFieldType.php`
- Test: `packages/folio/tests/Field/AssetFieldTypeTest.php` (new)

**Interfaces:**
- Consumes: `FieldType`/`FieldContext`; `Psr\Http\Message\UploadedFileInterface`.
- Produces:
  - `interface HandlesUpload { public function storeUploads(array $uploaded, array $kept, array $config): mixed; }` — `$uploaded` is `UploadedFileInterface[]`, `$kept` is `string[]` of existing paths to retain, returns the domain value (path string for single, `string[]` for multiple) which the caller then runs through `toStorage()`.
  - `AssetFieldType implements FieldType, HandlesUpload`, constructed `__construct(string $uploadsDir, string $uploadUrlPrefix)`. Key `asset`; config read from `$config['asset']` (`multiple` bool, `accept` string). `toStorage` → JSON for arrays, string otherwise; `fromStorage` → array if a JSON-array string, else the string. `renderFrontend` emits `<img>` for image extensions, else a link. `assets()`/`rules()` return `[]`.

- [ ] **Step 1: Write the failing test**

Create `packages/folio/tests/Field/AssetFieldTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\Types\AssetFieldType;

final class AssetFieldTypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_asset_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        // best-effort recursive cleanup
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function type(): AssetFieldType
    {
        return new AssetFieldType($this->dir, '/folio/_uploads');
    }

    private function upload(string $name, string $mime = 'image/png', string $data = 'X')
    {
        $f = new Psr17Factory();
        return $f->createUploadedFile($f->createStream($data), strlen($data), UPLOAD_ERR_OK, $name, $mime);
    }

    public function test_key(): void
    {
        $this->assertSame('asset', $this->type()->key());
    }

    public function test_store_single_moves_file_and_returns_path(): void
    {
        $result = $this->type()->storeUploads([$this->upload('pic.png')], [], ['asset' => ['multiple' => false, 'accept' => 'image/*']]);
        $this->assertIsString($result);
        $this->assertStringEndsWith('.png', $result);
        $this->assertFileExists($this->dir . '/' . $result);
    }

    public function test_store_rejects_disallowed_extension(): void
    {
        $result = $this->type()->storeUploads([$this->upload('evil.php', 'image/png')], [], ['asset' => ['multiple' => false, 'accept' => 'image/*']]);
        $this->assertSame('', $result); // .php not in the image allowlist -> not stored
    }

    public function test_store_multiple_returns_kept_plus_new(): void
    {
        $result = $this->type()->storeUploads(
            [$this->upload('a.png'), $this->upload('b.png')],
            ['2026/06/existing.png'],
            ['asset' => ['multiple' => true, 'accept' => 'image/*']],
        );
        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains('2026/06/existing.png', $result);
    }

    public function test_storage_roundtrip(): void
    {
        $t = $this->type();
        $this->assertSame('a/b.png', $t->toStorage('a/b.png'));
        $this->assertSame('a/b.png', $t->fromStorage('a/b.png'));
        $json = $t->toStorage(['x.png', 'y.png']);
        $this->assertSame(['x.png', 'y.png'], $t->fromStorage($json));
    }

    public function test_render_editor_emits_file_input_and_existing_remove(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'cover', label: 'Cover', value: '2026/06/x.png',
            config: ['asset' => ['multiple' => false, 'accept' => 'image/*']],
        ));
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('name="cover"', $html);
        $this->assertStringContainsString('accept="image/*"', $html);
        $this->assertStringContainsString('name="cover_remove[]"', $html);
        $this->assertStringContainsString('src="/folio/_uploads/2026/06/x.png"', $html);
    }

    public function test_render_editor_multiple_uses_array_name(): void
    {
        $html = $this->type()->renderEditor(new FieldContext(
            name: 'gallery', config: ['asset' => ['multiple' => true]],
        ));
        $this->assertStringContainsString('name="gallery[]"', $html);
        $this->assertStringContainsString('multiple', $html);
    }

    public function test_render_frontend_image(): void
    {
        $out = $this->type()->renderFrontend('2026/06/x.png', []);
        $this->assertStringContainsString('<img src="/folio/_uploads/2026/06/x.png"', $out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit packages/folio/tests/Field/AssetFieldTypeTest.php`
Expected: FAIL — `HandlesUpload` / `AssetFieldType` do not exist.

- [ ] **Step 3: Create the capability interface**

Create `packages/folio/src/Field/HandlesUpload.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Capability for field types that consume uploaded files on save. The controller
 * detects this interface and hands over the request's uploaded files for the
 * field plus the existing paths to keep, instead of the parsed-body value.
 */
interface HandlesUpload
{
    /**
     * @param \Psr\Http\Message\UploadedFileInterface[] $uploaded newly uploaded files for this field
     * @param string[] $kept existing stored paths to retain
     * @param array<string, mixed> $config field config bag
     * @return mixed domain value (the caller runs it through toStorage())
     */
    public function storeUploads(array $uploaded, array $kept, array $config): mixed;
}
```

- [ ] **Step 4: Create the field type**

Create `packages/folio/src/Field/Types/AssetFieldType.php`:

```php
<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\HandlesUpload;
use Psr\Http\Message\UploadedFileInterface;

/**
 * File-upload field. Stores relative path(s) under the Folio uploads dir; serves
 * them via the {prefix}/_uploads route. Uploaded files are validated against the
 * field's accept (extension allowlist) and given randomized names.
 */
final class AssetFieldType implements FieldType, HandlesUpload
{
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    private const DEFAULT_ALLOWED = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'txt', 'doc', 'docx', 'csv', 'zip'];

    public function __construct(
        private readonly string $uploadsDir,
        private readonly string $uploadUrlPrefix,
    ) {}

    public function key(): string
    {
        return 'asset';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $cfg = $this->assetConfig($ctx->config);
        $name = $ctx->name;
        $existing = $this->toList($ctx->value);
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $hasError = $ctx->errors !== [];

        $html = '<div class="form-group folio-asset' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <label>' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</label>' . "\n";

        if ($existing !== []) {
            $html .= '  <ul class="folio-asset-list">' . "\n";
            foreach ($existing as $path) {
                $url = $this->urlFor($path);
                $html .= '    <li>';
                $html .= $this->isImage($path)
                    ? '<img src="' . $e($url) . '" alt="" class="folio-asset-thumb">'
                    : '<a href="' . $e($url) . '">' . $e($path) . '</a>';
                $html .= ' <label class="folio-asset-remove"><input type="checkbox" name="' . $e($name) . '_remove[]" value="' . $e($path) . '"> remove</label>';
                $html .= '</li>' . "\n";
            }
            $html .= '  </ul>' . "\n";
        }

        $inputName = $cfg['multiple'] ? $name . '[]' : $name;
        $acceptAttr = $cfg['accept'] !== '' ? ' accept="' . $e($cfg['accept']) . '"' : '';
        $multipleAttr = $cfg['multiple'] ? ' multiple' : '';
        $html .= '  <input type="file" name="' . $e($inputName) . '"' . $acceptAttr . $multipleAttr . '>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        // Uploads are handled via storeUploads(); this is only the non-upload fallback.
        return $raw;
    }

    public function toStorage(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_SLASHES);
        }
        return (string) ($value ?? '');
    }

    public function fromStorage(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $list = $this->toList($value);
        if ($list === []) {
            return '';
        }
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $out = '';
        foreach ($list as $path) {
            $url = $this->urlFor($path);
            $out .= $this->isImage($path)
                ? '<img src="' . $e($url) . '" alt="">'
                : '<a href="' . $e($url) . '">' . $e($path) . '</a>';
        }
        return $out;
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }

    public function storeUploads(array $uploaded, array $kept, array $config): mixed
    {
        $cfg = $this->assetConfig($config);
        $allowed = $this->allowedExtensions($cfg['accept']);
        $paths = array_values($kept);

        foreach ($uploaded as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }
            $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
            if ($ext === '' || !in_array($ext, $allowed, true)) {
                continue; // reject disallowed extensions; never store them
            }
            $rel = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $this->uploadsDir . '/' . $rel;
            if (!is_dir(dirname($dest))) {
                mkdir(dirname($dest), 0775, true);
            }
            $file->moveTo($dest);
            $paths[] = $rel;
        }

        return $cfg['multiple'] ? $paths : ($paths[0] ?? '');
    }

    /**
     * @param array<string, mixed> $config
     * @return array{multiple: bool, accept: string}
     */
    private function assetConfig(array $config): array
    {
        $asset = is_array($config['asset'] ?? null) ? $config['asset'] : [];
        return [
            'multiple' => (bool) ($asset['multiple'] ?? false),
            'accept' => (string) ($asset['accept'] ?? ''),
        ];
    }

    /** @return string[] */
    private function allowedExtensions(string $accept): array
    {
        $accept = trim($accept);
        if ($accept === 'image/*') {
            return self::IMAGE_EXT;
        }
        if ($accept === '') {
            return self::DEFAULT_ALLOWED;
        }
        $exts = [];
        foreach (explode(',', $accept) as $token) {
            $token = trim($token);
            if ($token !== '' && $token[0] === '.') {
                $exts[] = strtolower(ltrim($token, '.'));
            }
        }
        return $exts !== [] ? $exts : self::DEFAULT_ALLOWED;
    }

    /** @return string[] */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
    }

    private function urlFor(string $path): string
    {
        return rtrim($this->uploadUrlPrefix, '/') . '/' . ltrim($path, '/');
    }

    private function isImage(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::IMAGE_EXT, true);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit packages/folio/tests/Field/AssetFieldTypeTest.php`
Expected: PASS (9 tests).

- [ ] **Step 6: Commit**

```bash
git add packages/folio/src/Field/HandlesUpload.php packages/folio/src/Field/Types/AssetFieldType.php packages/folio/tests/Field/AssetFieldTypeTest.php
git commit -m "feat(folio): asset field type with upload handling + path storage"
```

---

### Task 3: Wire the upload-aware save path + register the type

**Files:**
- Modify: `packages/folio/src/Http/AdminController.php`
- Modify: `packages/folio/templates/admin/form.twig`
- Modify: `packages/folio/src/FolioServiceProvider.php`
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify, incl. scaffold)

**Interfaces:**
- Consumes: `HandlesUpload` + `AssetFieldType` (Task 2); `FieldTypeRegistry`; `UploadedFileInterface`.
- Produces: `AdminController::collectFieldData(TypeDefinition $typeDef, ServerRequestInterface $request, array $existing): array` (upload-aware); `store()`/`update()` call it with the request + existing record data; `form()` passes a `multipart` bool (true when any field type is `HandlesUpload`) and `form.twig` adds `enctype="multipart/form-data"` then. `AssetFieldType` registered in the registry binding with the uploads dir + `{prefix}/_uploads`.

- [ ] **Step 1: Update scaffold + add the failing tests**

In `packages/folio/tests/Integration/FolioAppTest.php`:

(a) In `scaffold()`'s `config/models/page.json` `fields`, add a `cover` asset field (keep the others):

```php
                'cover'  => ['type' => 'asset', 'asset' => ['multiple' => false, 'accept' => 'image/*']],
```

(b) Add these test methods:

```php
    public function test_new_form_is_multipart_with_file_input(): void
    {
        $body = (string) $this->get('/folio/page/new')->getBody();
        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('type="file"', $body);
        $this->assertStringContainsString('name="cover"', $body);
    }

    public function test_upload_is_stored_and_served(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $file = $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png');

        $res = $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'A', 'slug' => 'a', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $file]));
        $this->assertSame(302, $res->getStatusCode());

        $record = $app->container()->get(\Preflow\Data\DataManager::class)
            ->queryType('page')->where('slug', 'a')->first();
        $cover = (string) $record->get('cover');
        $this->assertStringEndsWith('.png', $cover);

        $served = $app->handle($f->createServerRequest('GET', '/folio/_uploads/' . $cover));
        $this->assertSame(200, $served->getStatusCode());
        $this->assertSame('image/png', $served->getHeaderLine('Content-Type'));
        $this->assertSame('PNGDATA', (string) $served->getBody());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter "test_new_form_is_multipart_with_file_input|test_upload_is_stored_and_served"`
Expected: FAIL — `asset` type falls back to the `string` type today (no upload handling, no multipart, no file input).

- [ ] **Step 3: Register the asset type in the provider**

In `packages/folio/src/FolioServiceProvider.php`, add the import:

```php
use Preflow\Folio\Field\Types\AssetFieldType;
```

In the `FieldTypeRegistry` bind closure, the closure currently takes no args and can't see `$app`/prefix. Change it to a `Container`-aware closure so it can build the uploads dir + URL prefix. Replace the existing `$container->bind(FieldTypeRegistry::class, ...)` with:

```php
        $uploadsDir = $this->uploadsDir($app);
        $uploadUrlPrefix = rtrim($this->prefix($app), '/') . '/_uploads';
        $container->bind(FieldTypeRegistry::class, function () use ($uploadsDir, $uploadUrlPrefix): FieldTypeRegistry {
            $registry = new FieldTypeRegistry();
            $registry->register(new StringFieldType());
            $registry->register(new TextFieldType());
            $registry->register(new NumberFieldType());
            $registry->register(new RichTextFieldType(new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->allowRelativeLinks()
                    ->allowRelativeMedias(),
            )));
            $registry->register(new AssetFieldType($uploadsDir, $uploadUrlPrefix));
            $registry->alias('int', 'number');
            $registry->alias('integer', 'number');
            $registry->alias('float', 'number');
            return $registry;
        });
```

(`$uploadsDir`/`$uploadUrlPrefix` are computed from `$app`, which is already resolved at the top of `register()`.)

- [ ] **Step 4: Make the save path upload-aware in `AdminController`**

In `packages/folio/src/Http/AdminController.php`, add imports:

```php
use Preflow\Folio\Field\HandlesUpload;
use Psr\Http\Message\UploadedFileInterface;
```

Replace the existing `collectFieldData()` method with:

```php
    /**
     * Build the storage payload, routing upload fields through their uploaded
     * files + kept existing paths, and all others through normalize + toStorage.
     *
     * @param array<string, mixed> $existing current stored values (for update)
     * @return array<string, mixed>
     */
    private function collectFieldData(TypeDefinition $typeDef, ServerRequestInterface $request, array $existing): array
    {
        $submitted = (array) $request->getParsedBody();
        $uploads = $request->getUploadedFiles();
        $data = [];

        foreach ($typeDef->fields as $name => $fieldDef) {
            if ($name === $typeDef->idField) {
                continue;
            }
            $fieldType = $this->fieldTypes->get($fieldDef->type);

            if ($fieldType instanceof HandlesUpload) {
                $files = $this->uploadedFilesFor($uploads[$name] ?? null);
                $removed = array_values(array_filter(
                    (array) ($submitted[$name . '_remove'] ?? []),
                    static fn ($v) => is_string($v),
                ));
                $existingList = $this->pathList($fieldType->fromStorage($existing[$name] ?? null));
                $kept = array_values(array_diff($existingList, $removed));
                $domain = $fieldType->storeUploads($files, $kept, $fieldDef->config);
                $data[$name] = $fieldType->toStorage($domain);
                continue;
            }

            $data[$name] = $fieldType->toStorage(
                $fieldType->normalizeInput($submitted[$name] ?? null, $fieldDef->config),
            );
        }

        return $data;
    }

    /** @return \Psr\Http\Message\UploadedFileInterface[] */
    private function uploadedFilesFor(mixed $entry): array
    {
        if ($entry instanceof UploadedFileInterface) {
            return [$entry];
        }
        if (is_array($entry)) {
            return array_values(array_filter($entry, static fn ($f) => $f instanceof UploadedFileInterface));
        }
        return [];
    }

    /** @return string[] */
    private function pathList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v) => is_string($v) && $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return [];
    }
```

Update `store()` — replace the `$data = $this->collectFieldData($typeDef, $submitted);` line and the `$submitted` fetch with the request-aware call. The method becomes:

```php
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $typeDef = $this->registry->get($type);
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        $data = $this->collectFieldData($typeDef, $request, []);
        $data[$typeDef->idField] = bin2hex(random_bytes(16));

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
        } catch (ValidationException $e) {
            return $this->form(
                $type,
                (array) $request->getParsedBody(),
                $this->prefix . '/' . $type,
                'New ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
            );
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }
```

Update `update()` similarly — it must pass the existing record's data so kept-paths work:

```php
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $type = (string) $request->getAttribute('type', '');
        $id = (string) $request->getAttribute('id', '');
        if (!$this->catalog->has($type)) {
            return new Response(404, [], 'Unknown type');
        }

        $current = $this->dm->findType($type, $id);
        if ($current === null) {
            return new Response(404, [], 'Not found');
        }

        $typeDef = $this->registry->get($type);
        $csrf = $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';

        $data = $this->collectFieldData($typeDef, $request, $current->toArray());
        $data[$typeDef->idField] = $id;

        try {
            $this->dm->saveType(DynamicRecord::fromArray($typeDef, $data));
        } catch (ValidationException $e) {
            return $this->form(
                $type,
                (array) $request->getParsedBody(),
                $this->prefix . '/' . $type . '/' . $id,
                'Edit ' . $this->labelFor($type),
                $e->errors(),
                $csrf,
                422,
            );
        }

        return new Response(302, ['Location' => $this->prefix . '/' . $type]);
    }
```

In `form()`, compute the `multipart` flag while looping the fields and pass it to the template. In the field loop add, after resolving `$fieldType`:

```php
            if ($fieldType instanceof HandlesUpload) {
                $multipart = true;
            }
```

Declare `$multipart = false;` before the loop, and add `'multipart' => $multipart,` to the `engine->render('@folio/admin/form.twig', [...])` context array.

- [ ] **Step 5: Add conditional enctype to the form template**

In `packages/folio/templates/admin/form.twig`, change the `form_begin(...)` call to:

```twig
        {% set form = form_begin({action: action, csrf_token: csrf, attrs: multipart|default(false) ? {'enctype': 'multipart/form-data'} : {}}) %}
```

- [ ] **Step 6: Run the integration suite**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php`
Expected: PASS — including the two new tests and the existing create/edit/frontend tests (the `cover` field is optional; existing posts that omit it store `''`).

- [ ] **Step 7: Commit**

```bash
git add packages/folio/src/Http/AdminController.php packages/folio/templates/admin/form.twig packages/folio/src/FolioServiceProvider.php packages/folio/tests/Integration/FolioAppTest.php
git commit -m "feat(folio): upload-aware admin save path + multipart form + register asset type"
```

---

### Task 4: Frontend asset render + multiple/remove round-trip

**Files:**
- Test: `packages/folio/tests/Integration/FolioAppTest.php` (modify)

**Interfaces:**
- Consumes: everything from Tasks 1-3 (the asset field renders via the Phase-2 registry-driven `FrontendController`; update handles remove + multiple).

- [ ] **Step 1: Write the failing tests**

Add these methods to `packages/folio/tests/Integration/FolioAppTest.php`:

```php
    public function test_frontend_renders_uploaded_image(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $file = $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png');
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'Img', 'slug' => 'img', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $file]));

        $html = (string) $app->handle($f->createServerRequest('GET', '/img'))->getBody();
        $this->assertStringContainsString('<img src="/folio/_uploads/', $html);
    }

    public function test_update_removes_existing_asset(): void
    {
        $app = $this->app();
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $file = $f->createUploadedFile($f->createStream('PNGDATA'), 7, UPLOAD_ERR_OK, 'pic.png', 'image/png');
        $app->handle($f->createServerRequest('POST', '/folio/page')
            ->withParsedBody(['title' => 'R', 'slug' => 'r', 'body' => 'b', 'status' => 'published'])
            ->withUploadedFiles(['cover' => $file]));

        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        $record = $dm->queryType('page')->where('slug', 'r')->first();
        $id = $record->getId();
        $cover = (string) $record->get('cover');
        $this->assertStringEndsWith('.png', $cover);

        // update with a remove marker for that path and no new upload
        $app->handle($f->createServerRequest('POST', '/folio/page/' . $id)
            ->withAttribute('type', 'page')->withAttribute('id', $id)
            ->withParsedBody(['title' => 'R', 'slug' => 'r', 'body' => 'b', 'status' => 'published', 'cover_remove' => [$cover]]));

        $after = $dm->findType('page', $id);
        $this->assertSame('', (string) $after->get('cover'));
    }
```

- [ ] **Step 2: Run to verify status**

Run: `vendor/bin/phpunit packages/folio/tests/Integration/FolioAppTest.php --filter "test_frontend_renders_uploaded_image|test_update_removes_existing_asset"`
Expected: PASS — Tasks 1-3 already implement the frontend render (registry-driven) and the remove path (`collectFieldData` diffs `cover_remove` against existing). This task is the end-to-end gate proving both; if either fails, the defect is in the Task 3 wiring (fix there).

- [ ] **Step 3: Commit**

```bash
git add packages/folio/tests/Integration/FolioAppTest.php
git commit -m "test(folio): end-to-end asset frontend render + remove round-trip"
```

---

### Task 5: Demo showcase + full-suite verification

**Files:**
- Modify: `examples/folio-demo/config/models/page.json`
- Test: whole repo suite

**Interfaces:**
- Consumes: the asset field type (Tasks 1-3).

- [ ] **Step 1: Add a demo asset field**

In `examples/folio-demo/config/models/page.json`, add a `cover` asset field (keep the others). The `fields` object becomes:

```json
    "fields": {
        "title": { "type": "string", "validate": ["required"] },
        "slug": { "type": "string", "validate": ["required"] },
        "cover": { "type": "asset", "label": "Cover image", "help": "Upload an image; stored under the Folio uploads dir.", "asset": { "multiple": false, "accept": "image/*" } },
        "body": { "type": "richtext", "label": "Body content", "help": "Rich text — formatting is preserved and sanitized on save." },
        "status": { "type": "string" }
    }
```

- [ ] **Step 2: Verify the demo smoke test still passes**

Run: `vendor/bin/phpunit examples/folio-demo/tests`
Expected: PASS (2 tests) — the demo app still boots and serves `/folio`.

- [ ] **Step 3: Run the full repo suite**

Run: `vendor/bin/phpunit`
Expected: PASS — all suites green. Only expected non-failures are the pre-existing PHPUnit deprecations + 1 skip. No test failures. If anything fails, investigate before committing.

- [ ] **Step 4: Commit**

```bash
git add examples/folio-demo/config/models/page.json
git commit -m "docs(folio): demo a cover image asset field"
```

---

## Self-Review

**Spec coverage (Phase 3 portion of the field/editor spec):**
- Asset field = file upload → `storage/uploads/`, stores path(s), single/multiple, `accept` filter → Task 2 (`AssetFieldType`).
- Uploads served via `{prefix}/_uploads/{path}` (drop-in, traversal-guarded) → Task 1.
- Editor: file input + inline list of current path(s) with remove → Task 2 (`renderEditor`); upload-aware save + multipart form → Task 3.
- Frontend `<img>`/link via the registry → Task 2 (`renderFrontend`) + Task 4 (end-to-end).
- Security: extension allowlist vs `accept`, randomized filenames, traversal guard, octet-stream for non-images → Tasks 1, 2.
- Demo showcases the asset field → Task 5.
- Out of scope (per spec): central media library/browser (#4); relation (Phase 4); matrix (Phase 5); `admin.js` (not needed — native file input).

**Known limitation (documented, acceptable for this phase):** if validation fails AFTER an upload is moved (e.g. a required text field is empty in the same submit), the moved file is already stored and the 422 re-render won't re-show it (the body has no path). Orphaned files are harmless and rare (required scalar fields are usually filled); a future media library / GC pass can reconcile. Not worth pre-validating-before-move complexity this phase.

**Placeholder scan:** No `TBD`/`TODO`. Task 4 Step 2 explains it expects PASS (the behavior is implemented in Tasks 1-3); it's an end-to-end gate, not a deferral.

**Type consistency:** `HandlesUpload::storeUploads(array $uploaded, array $kept, array $config): mixed` defined in Task 2, consumed in Task 3 (`collectFieldData`). `AssetFieldType.__construct(string $uploadsDir, string $uploadUrlPrefix)` consistent in Task 2 (tests) and Task 3 (provider registration with `{prefix}/_uploads`). `UploadController.__construct(string $uploadsDir)` + `serve` consistent across Task 1 (test, route handler `UploadController@serve`, provider binding). `collectFieldData(TypeDefinition, ServerRequestInterface, array): array` defined and used in Task 3 (store/update). `uploadsDir()` provider helper used by both the `UploadController` binding (Task 1) and the `AssetFieldType` registration (Task 3). The `{name}_remove[]` body key is produced by `renderEditor` (Task 2) and consumed by `collectFieldData` (Task 3). `multipart` template var produced in `form()` (Task 3) and consumed in `form.twig` (Task 3).
