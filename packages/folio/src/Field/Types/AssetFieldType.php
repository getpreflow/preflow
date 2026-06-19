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
