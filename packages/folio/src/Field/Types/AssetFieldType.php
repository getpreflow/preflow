<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Media\MediaMessages;
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
final class AssetFieldType implements FieldType, HandlesUpload, \Preflow\Folio\Field\DisplaysInAdmin
{
    private const IMAGE_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    private const DEFAULT_ALLOWED = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'txt', 'doc', 'docx', 'csv', 'zip'];

    public function __construct(
        private readonly string $uploadsDir,
        private readonly string $uploadUrlPrefix,
        private readonly ?\Preflow\Media\ImageTag $images = null,
        private readonly ?\Preflow\Media\MediaLibrary $library = null,
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

    /**
     * Board LibFields: each chosen file as a card (a 16:10 thumbnail with the focal point, the name
     * in mono, kind and size, "No alt text yet" for an image without one, Remove); without one, a
     * dashed drop zone. Choose (the library in the drawer) and Upload (a plain file input, stored on
     * save) work without script; with it (fields.js) a file dropped anywhere on the field goes to the
     * library through its upload route (POST {prefix}/assets, its permission and CSRF token) and is
     * chosen.
     */
    public function renderEditor(FieldContext $ctx): string
    {
        $cfg = $this->assetConfig($ctx->config);
        $name = $ctx->name;
        $selection = self::selectionName($name) . '[]';
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $name));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $key, array $params = []): string => \Preflow\Folio\FolioText::get($key, $params);
        $hasError = $ctx->errors !== [];
        $canUpload = $this->allows('assets.upload');
        $droppable = $canUpload && $this->library !== null;
        $images = trim($cfg['accept']) === 'image/*';

        $attrs = ' data-folio-asset-field data-selection-name="' . $e($selection) . '" data-multiple="' . ($cfg['multiple'] ? '1' : '0') . '"';
        if ($this->library !== null) {
            // The record's language travels along: the cards judge alt text in it (AssetCard).
            $locale = $ctx->locale !== null && $ctx->locale !== ($ctx->locales?->default ?? $ctx->locale) ? '&locale=' . rawurlencode($ctx->locale) : '';
            $attrs .= ' data-picker-url="' . $e(rtrim($this->adminPrefix, '/') . '/assets?picker=1&multiple=' . ($cfg['multiple'] ? '1' : '0') . '&private=' . ($cfg['private'] ? '1' : '0') . $locale) . '"';
        }
        if ($droppable) {
            $attrs .= ' data-upload-url="' . $e(rtrim($this->adminPrefix, '/') . '/assets') . '" data-accept="' . $e($cfg['accept']) . '"'
                . ' data-private="' . ($cfg['private'] ? '1' : '0') . '"' . ($ctx->locale !== null ? ' data-locale="' . $e($ctx->locale) . '"' : '')
                . ' data-text-refused="' . $e($t('folio.fields.drop_refused')) . '" data-text-uploading="' . $e($t('folio.fields.uploading')) . '"'
                . ' data-text-failed="' . $e($t('folio.fields.upload_failed')) . '"';
        }
        // A group named by its label: the cards, Choose and Upload belong to it.
        $labelId = preg_replace('/[^A-Za-z0-9_-]/', '-', $name) . '-label';
        $html = '<div class="form-group folio-asset-field' . ($hasError ? ' has-error' : '') . '"' . $attrs . ' role="group" aria-labelledby="' . $e($labelId) . '">' . "\n";
        $html .= '  <span class="form-label" id="' . $e($labelId) . '">' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</span>' . "\n";

        // The empty marker makes an emptied selection distinguishable from a form that never posted one.
        $html .= '  <input type="hidden" name="' . $e($selection) . '" value="">' . "\n";
        $html .= '  <div class="folio-asset-box"' . ($droppable ? ' data-folio-asset-drop' : '') . '>' . "\n";
        $html .= '  <ul class="folio-asset-selection">';
        foreach ($this->toList($this->fromStorage($ctx->value)) as $value) {
            $html .= $this->item($value, $selection, $this->describe($value, $ctx));
        }
        $html .= '</ul>' . "\n";
        if ($droppable) {
            $html .= '  <p class="folio-asset-empty">' . \Preflow\Folio\View\InlineIcon::svg('ui-upload') . '<strong>' . $e($t($images ? 'folio.fields.drop_image_or_choose' : 'folio.fields.drop_here')) . '</strong></p>' . "\n";
        }

        $html .= '  <div class="folio-asset-actions">';
        if ($this->library !== null && $this->allows('assets.view')) {
            $html .= '<button type="button" class="btn btn-secondary btn-sm" data-folio-asset-choose>' . $e($t('folio.fields.choose')) . '</button> ';
        }
        if ($canUpload) {
            $inputName = $cfg['multiple'] ? $name . '[]' : $name;
            $acceptAttr = $cfg['accept'] !== '' ? ' accept="' . $e($cfg['accept']) . '"' : '';
            $multipleAttr = $cfg['multiple'] ? ' multiple' : '';
            // The real file input inside a button-styled label: without script it uploads on save; with
            // script (folio-js) it is hidden only visually (still focusable, the label shows its focus)
            // and fields.js sends a chosen file through the same upload as a drop. A filled single field
            // reads "Replace" (CSS, by whether a card is there). The live region names the chosen file.
            $html .= '<label class="btn btn-secondary btn-sm folio-asset-upload"><span class="folio-upload-label">' . $e($t('folio.fields.upload')) . '</span>'
                . (!$cfg['multiple'] ? '<span class="folio-upload-label is-replace">' . $e($t('folio.fields.replace')) . '</span>' : '')
                . '<input type="file" class="folio-asset-file" name="' . $e($inputName) . '"' . $acceptAttr . $multipleAttr . '></label>'
                . '<span class="folio-asset-upload-name" data-folio-upload-name aria-live="polite"></span>';
        }
        $html .= '</div>' . "\n";
        $html .= '  </div>' . "\n";
        if ($droppable || $this->library !== null) {
            // The card fields.js / admin.js copy for a picked or dropped file.
            $html .= '  <template data-folio-asset-template>' . $this->item('', $selection, ['thumb' => '', 'name' => '', 'href' => null, 'meta' => '', 'warn' => false, 'focal' => null]) . '</template>' . "\n";
        }

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        if ($droppable) {
            $html .= '  <div class="form-error" data-folio-asset-error hidden></div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * One chosen file's card.
     *
     * @param array{thumb: ?string, name: string, href: ?string, meta: string, warn: bool, focal: ?array{int, int}} $card
     */
    private function item(string $value, string $selection, array $card): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $template = $value === '';
        $html = '<li class="folio-asset-item" data-asset-item>';
        if ($template || ($card['thumb'] ?? null) !== null) {
            $html .= '<span class="folio-asset-preview"><img src="' . $e((string) ($card['thumb'] ?? '')) . '" alt="" class="folio-asset-thumb">'
                // Placed by script (fields.js) from data-focal: no style attribute under a strict CSP.
                . '<span class="folio-asset-focal"' . ($card['focal'] !== null ? ' data-focal="' . $card['focal'][0] . ',' . $card['focal'][1] . '"' : '') . ' aria-hidden="true" hidden></span></span>';
        }
        if (!$template && ($card['thumb'] ?? null) === null) {
            $html .= '<span class="folio-asset-preview is-file" aria-hidden="true">' . \Preflow\Folio\View\InlineIcon::svg('ui-file') . '</span>';
        } elseif ($template) {
            // A file without a thumbnail (a PDF): admin.js keeps this one instead of the image.
            $html .= '<span class="folio-asset-preview is-file" aria-hidden="true" hidden>' . \Preflow\Folio\View\InlineIcon::svg('ui-file') . '</span>';
        }
        $html .= ''
            . '<span class="folio-asset-info"><span class="folio-asset-name">'
            . ($card['href'] !== null && !$template ? '<a href="' . $e($card['href']) . '">' . $e($card['name']) . '</a>' : $e($card['name'])) . '</span>'
            . '<span class="folio-asset-meta"' . ($card['meta'] === '' && !$template ? ' hidden' : '') . '>' . $e($card['meta']) . '</span>'
            . ($card['warn'] || $template ? '<span class="folio-asset-warn"' . ($template ? ' hidden' : '') . '>' . $e(\Preflow\Folio\FolioText::get('folio.fields.no_alt')) . '</span>' : '')
            . '</span>'
            . '<input type="hidden" name="' . $e($selection) . '" value="' . $e($value) . '">'
            . '<button type="button" class="btn btn-ghost btn-sm" data-tone="danger" data-folio-asset-remove aria-label="' . $e(\Preflow\Folio\FolioText::get('folio.fields.remove_named', ['label' => $card['name']])) . '">'
            . $e(\Preflow\Folio\FolioText::get('folio.common.remove')) . '</button>';

        return $html . '</li>';
    }

    /**
     * What a chosen file's card shows. A library asset: thumbnail, focal point, name, kind and
     * size, whether an image lacks alt text (in the record's language, else the default). A file in
     * the bin keeps its place (restoring it brings it back) and says so, linking to the bin for
     * those who may open it (assets.delete). An older stored path: its file.
     *
     * @return array{thumb: ?string, name: string, href: ?string, meta: string, warn: bool, focal: ?array{int, int}}
     */
    private function describe(string $value, ?FieldContext $ctx = null): array
    {
        $asset = $this->library?->find($value);
        if ($asset !== null) {
            // The same card the picker and the upload's JSON answer give (AssetCard).
            [$locale, $default] = $this->cardLocales($ctx);
            $card = \Preflow\Folio\Media\AssetCard::of($asset, $this->library, $locale, $default);

            return [
                'thumb' => $card['thumb'] !== '' ? $card['thumb'] : null,
                'name' => $card['label'],
                'href' => null,
                'meta' => $card['meta'],
                'warn' => $card['warn'],
                'focal' => $card['focal'] !== '' ? array_map('intval', explode(',', $card['focal'])) : null,
            ];
        }
        $binned = $this->library?->findBinned($value);
        if ($binned !== null) {
            return ['thumb' => null, 'name' => self::inBin($binned), 'href' => $this->allows('assets.delete') ? rtrim($this->adminPrefix, '/') . '/assets/bin' : null, 'meta' => '', 'warn' => false, 'focal' => null];
        }
        $url = $this->urlFor($value);

        return ['thumb' => $this->isImage($value) ? $url : null, 'name' => basename($value), 'href' => $this->isImage($value) ? null : $url, 'meta' => '', 'warn' => false, 'focal' => null];
    }

    /** @return array{string, string} the record's language and the default, for the alt-text warning */
    private function cardLocales(?FieldContext $ctx): array
    {
        $default = $ctx?->locales?->default ?? $ctx?->locale ?? 'en';

        return [$ctx?->locale ?? $default, $default];
    }

    private static function inBin(\Preflow\Media\Asset $asset): string
    {
        return \Preflow\Folio\FolioText::get('folio.assets.in_bin', ['label' => $asset->label()]);
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

    /** The file names (the library's original name, else the stored path), comma-separated and escaped. */
    public function renderAdmin(mixed $value, array $config): string
    {
        $names = array_map(function (string $v): string {
            $asset = $this->library?->find($v);
            if ($asset !== null) {
                return $asset->label();
            }
            $binned = $this->library?->findBinned($v);

            return $binned !== null ? self::inBin($binned) : $v;
        }, $this->toList($value));

        return htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8');
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

    /** fields.js: the drop zone (admin.js runs Choose and Remove). */
    public function assets(): array
    {
        return ['fields.js'];
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
            // Only values that exist: asset ids (one in the bin too: restoring it brings it back), or paths this field wrote earlier.
            : $this->matchingPrivacy(
                array_values(array_filter($submittedIds, fn ($v) => is_string($v) && $v !== '' && ($this->library?->isAssetId($v) === true || $this->library?->findBinned($v) !== null || $this->isStoredUpload($v)))),
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
            $asset = $this->library?->find($value) ?? $this->library?->findBinned($value);
            if (($asset?->private ?? false) === $private) {
                $kept[] = $value;
                continue;
            }
            $label = $asset?->originalName ?? basename($value);
            $this->errors[] = $private
                ? \Preflow\Folio\FolioText::get('folio.fields.public_file', ['label' => $label])
                : \Preflow\Folio\FolioText::get('folio.fields.private_file', ['label' => $label]);
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
        } catch (\Preflow\Media\AssetUploadException $e) {
            $this->errors[] = MediaMessages::text($e->getMessage());
            return null;
        }
        if ($asset === null) {
            return null;
        }
        if (!self::accepts($asset, $accept)) {
            $this->library?->discard($asset->id); // just created: nothing references it yet
            $this->errors[] = ($file->getClientFilename() ?? 'upload') . ': this field only accepts ' . trim($accept) . '.';
            return null;
        }

        return $asset->id;
    }

    /** "accept" as in HTML: MIME wildcards (image/*), MIME types and extensions (.jpg), comma-separated. */
    private static function accepts(\Preflow\Media\Asset $asset, string $accept): bool
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
