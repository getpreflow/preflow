<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\HandlesUpload;
use Psr\Http\Message\UploadedFileInterface;

/**
 * File field. With the asset library (the normal setup) it stores asset ids:
 * the editor shows the selection as thumbnails with "Choose" (the library as a
 * picker in the drawer) and a plain file input that uploads into the library on
 * save, so it works without JS. The posted selection ({name}_ids[], led by an
 * empty marker) is authoritative; without it the stored value stays.
 *
 * Older values are relative paths under the Folio uploads dir (served by
 * {prefix}/_uploads); they keep working. Without a library the field writes
 * such paths itself (extension allowlist, randomized names).
 */
final class AssetFieldType implements FieldType, HandlesUpload
{
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    private const DEFAULT_ALLOWED = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'txt', 'doc', 'docx', 'csv', 'zip'];

    public function __construct(
        private readonly string $uploadsDir,
        private readonly string $uploadUrlPrefix,
        private readonly ?\Preflow\Folio\Assets\ImageTag $images = null,
        private readonly ?\Preflow\Folio\Assets\AssetLibrary $library = null,
        private readonly string $adminPrefix = '/folio',
        /** @var (\Closure(string): bool)|null permission check; null allows everything */
        private readonly ?\Closure $can = null,
    ) {}

    /** @var list<string> refused uploads since the last drainErrors() */
    private array $errors = [];

    /**
     * Messages for uploads the library refused (type, size, "accept") since
     * the last call; the form shows them instead of dropping files silently.
     *
     * @return list<string>
     */
    public function drainErrors(): array
    {
        [$errors, $this->errors] = [$this->errors, []];

        return $errors;
    }

    public function key(): string
    {
        return 'asset';
    }

    private function allows(string $permission): bool
    {
        return $this->can === null || ($this->can)($permission);
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $cfg = $this->assetConfig($ctx->config);
        $name = $ctx->name;
        $selection = self::selectionName($name) . '[]';
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $hasError = $ctx->errors !== [];

        $attrs = ' data-folio-asset-field data-selection-name="' . $e($selection) . '" data-multiple="' . ($cfg['multiple'] ? '1' : '0') . '"';
        if ($this->library !== null) {
            $attrs .= ' data-picker-url="' . $e(rtrim($this->adminPrefix, '/') . '/assets?picker=1&multiple=' . ($cfg['multiple'] ? '1' : '0') . '&private=' . ($cfg['private'] ? '1' : '0')) . '"';
        }
        $html = '<div class="form-group folio-asset' . ($hasError ? ' has-error' : '') . '"' . $attrs . '>' . "\n";
        $html .= '  <label>' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</label>' . "\n";

        // The empty marker makes an emptied selection distinguishable from a form that never posted one.
        $html .= '  <input type="hidden" name="' . $e($selection) . '" value="">' . "\n";
        $html .= '  <ul class="folio-asset-selection">' . "\n";
        foreach ($this->toList($this->fromStorage($ctx->value)) as $value) {
            [$thumb, $text, $href] = $this->describe($value);
            $html .= '    <li class="folio-asset-item" data-asset-item>';
            $html .= $thumb !== null
                ? '<img src="' . $e($thumb) . '" alt="" class="folio-asset-thumb">'
                : '<a href="' . $e($href) . '">' . $e($text) . '</a>';
            $html .= '<input type="hidden" name="' . $e($selection) . '" value="' . $e($value) . '">';
            $html .= '<button type="button" class="btn btn-ghost" data-folio-asset-remove aria-label="Remove ' . $e($text) . '">Remove</button>';
            $html .= '</li>' . "\n";
        }
        $html .= '  </ul>' . "\n";

        $html .= '  <div class="folio-asset-actions">';
        if ($this->library !== null && $this->allows('assets.view')) {
            $html .= '<button type="button" class="btn btn-secondary" data-folio-asset-choose>Choose</button> ';
        }
        if ($this->allows('assets.upload')) {
            $inputName = $cfg['multiple'] ? $name . '[]' : $name;
            $acceptAttr = $cfg['accept'] !== '' ? ' accept="' . $e($cfg['accept']) . '"' : '';
            $multipleAttr = $cfg['multiple'] ? ' multiple' : '';
            $html .= '<label class="folio-asset-upload">Upload <input type="file" name="' . $e($inputName) . '"' . $acceptAttr . $multipleAttr . '></label>';
        }
        $html .= '</div>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Thumbnail URL (images), label and link of a stored value.
     *
     * @return array{0: ?string, 1: string, 2: string}
     */
    private function describe(string $value): array
    {
        $asset = $this->library?->find($value);
        if ($asset !== null) {
            return [$this->library->thumbnailUrl($asset), $asset->label(), $this->library->url($asset)];
        }
        $url = $this->urlFor($value);

        return [$this->isImage($value) ? $url : null, basename($value), $url];
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
        if ($this->images !== null) {
            return implode('', array_map(fn (string $v): string => $this->images->render($v), $list));
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

    /**
     * The value to store from the editor's selection and new uploads.
     *
     * @param list<mixed>|null              $submittedIds {name}_ids[] as posted; null when absent
     *                                                    (keeps the stored value)
     * @param list<UploadedFileInterface>   $uploads
     * @param array<string, mixed>          $config
     * @return string a single value, or a JSON list for "multiple" fields
     */
    public function resolve(?array $submittedIds, mixed $existing, array $uploads, array $config, bool $writeUploads, ?string $by): string
    {
        $base = $submittedIds === null
            ? $this->toList($this->fromStorage($existing))
            // Only values that exist: asset ids, or paths this field wrote earlier.
            : $this->matchingPrivacy(
                array_values(array_filter($submittedIds, fn ($v) => is_string($v) && $v !== '' && ($this->library?->isAssetId($v) === true || $this->isStoredUpload($v)))),
                $this->assetConfig($config)['private'],
            );

        return (string) $this->toStorage($this->limit($this->addUploads($base, $writeUploads ? $uploads : [], $config, $by), $config));
    }

    /**
     * Posted values whose privacy matches the field's: a private field takes only
     * private assets (a public one, or a legacy upload, stays world-readable), a
     * public field only public ones. The others are refused with a message.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private function matchingPrivacy(array $values, bool $private): array
    {
        $kept = [];
        foreach ($values as $value) {
            $asset = $this->library?->find($value);
            if (($asset?->private ?? false) === $private) {
                $kept[] = $value;
                continue;
            }
            $label = $asset?->originalName ?? basename($value);
            $this->errors[] = $private
                ? "{$label} is a public file; upload it here to make it private."
                : "{$label} is a private file; it can only be used in a private field.";
        }

        return $kept;
    }

    /** Trusted $kept values plus the uploads (HandlesUpload). */
    public function storeUploads(array $uploaded, array $kept, array $config): mixed
    {
        return $this->limit($this->addUploads(array_values($kept), $uploaded, $config, null), $config);
    }

    /**
     * @param list<string> $values
     * @param array<mixed> $uploads
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function addUploads(array $values, array $uploads, array $config, ?string $by): array
    {
        $cfg = $this->assetConfig($config);
        foreach ($uploads as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }
            if ($cfg['private'] && $this->library === null) {
                // Legacy uploads are served publicly: never store a private file there.
                $this->errors[] = ($file->getClientFilename() ?? 'upload') . ': private files need the asset library; it was not stored.';
                continue;
            }
            $stored = $this->library !== null ? $this->storeInLibrary($file, $cfg['accept'], $by, $cfg['private']) : $this->storeLegacy($file, $cfg['accept']);
            if ($stored !== null) {
                $values[] = $stored;
            }
        }

        return $values;
    }

    /** The asset id, or null (with a message) when the library refuses the file or it does not match "accept". */
    private function storeInLibrary(UploadedFileInterface $file, string $accept, ?string $by, bool $private): ?string
    {
        try {
            $asset = $this->library?->upload($file, $by, private: $private);
        } catch (\Preflow\Folio\Assets\AssetUploadException $e) {
            $this->errors[] = $e->getMessage();
            return null;
        }
        if ($asset === null) {
            return null;
        }
        if (!self::accepts($asset, $accept)) {
            $this->library?->delete($asset->id);
            $this->errors[] = ($file->getClientFilename() ?? 'upload') . ': this field only accepts ' . trim($accept) . '.';
            return null;
        }

        return $asset->id;
    }

    /** "accept" as in HTML: MIME wildcards (image/*), MIME types and extensions (.jpg), comma-separated. */
    private static function accepts(\Preflow\Folio\Assets\Asset $asset, string $accept): bool
    {
        $tokens = array_filter(array_map('trim', explode(',', strtolower($accept))));
        if ($tokens === []) {
            return true;
        }
        $ext = strtolower(pathinfo($asset->path, PATHINFO_EXTENSION));
        foreach ($tokens as $token) {
            $matches = match (true) {
                str_starts_with($token, '.') => in_array($ext, [ltrim($token, '.'), $token === '.jpeg' ? 'jpg' : null], true),
                str_ends_with($token, '/*') => str_starts_with($asset->mime, substr($token, 0, -1)),
                default => $asset->mime === $token,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function storeLegacy(UploadedFileInterface $file, string $accept): ?string
    {
        $ext = strtolower(pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $this->allowedExtensions($accept), true)) {
            return null; // reject disallowed extensions; never store them
        }
        $rel = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $this->uploadsDir . '/' . $rel;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }
        $file->moveTo($dest);

        return $rel;
    }

    /**
     * @param list<string>         $values
     * @param array<string, mixed> $config
     * @return list<string>|string the list, or for single fields the last value
     */
    private function limit(array $values, array $config): array|string
    {
        return $this->assetConfig($config)['multiple'] ? array_values(array_unique($values)) : ($values === [] ? '' : $values[count($values) - 1]);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{multiple: bool, accept: string, private: bool}
     */
    private function assetConfig(array $config): array
    {
        $asset = is_array($config['asset'] ?? null) ? $config['asset'] : [];
        return [
            'multiple' => (bool) ($asset['multiple'] ?? false),
            'accept' => (string) ($asset['accept'] ?? ''),
            // Uploads go to the private directory, served only through the gated URL.
            'private' => ($asset['private'] ?? false) === true,
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

    /** "cover" -> "cover_ids"; nested "a[b][cover]" -> "a[b][cover_ids]" (PHP ignores text after the last bracket). */
    public static function selectionName(string $name): string
    {
        return str_ends_with($name, ']') ? substr($name, 0, -1) . '_ids]' : $name . '_ids';
    }

    /** The absolute file of a legacy upload path, or null when it is not one (or is gone). */
    public function legacyFile(string $path): ?string
    {
        return $this->isStoredUpload($path) ? $this->uploadsDir . '/' . $path : null;
    }

    /** A path this field type wrote: "YYYY/MM/<32 hex>.<ext>" that exists in the uploads dir. */
    public function isStoredUpload(string $path): bool
    {
        return preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,8}$#', $path) === 1 && is_file($this->uploadsDir . '/' . $path);
    }
}
