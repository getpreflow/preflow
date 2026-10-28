<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Data\Locales;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\RelationFieldType;
use Preflow\Folio\FolioText;
use Preflow\Folio\Media\MediaMessages;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Media\Asset;
use Preflow\Media\AssetPage;
use Preflow\Media\AssetUploadException;
use Preflow\Media\AssetVersion;
use Preflow\Media\Cleanup\AssetCleanup;
use Preflow\Media\Color\ColorRules;
use Preflow\Media\CropRect;
use Preflow\Media\Image\Format;
use Preflow\Media\Image\Formats;
use Preflow\Media\MediaConfig;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\CopyReferences;
use Preflow\Media\Usage\PosterReferences;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * {prefix}/assets: the media library. Grid/list, search, kind filter, the filters of
 * config/media.php ("filters"), multi-file upload, an asset page at {prefix}/assets/{id} (Details tab: alt per language,
 * title, focal point, video poster, the extra fields with Folio's field types) with usage,
 * colour swatches, replace and the version list (open, restore, delete), and delete (the
 * library refuses it while used). With ?picker=1 it renders in the drawer layout and each
 * asset gets a "Select" button. {prefix}/assets/{id}/edit is the Edit image screen: rotate,
 * mirror, the image crop and the cover presets' cuts (Cropper.js draws the area). With
 * bin_dir a delete moves the file into the bin ({prefix}/assets/bin lists and restores);
 * without it the library warns that deletes are for good. With cleanup on, "Unused only"
 * lists what cleanup would bin. An original with copies is one tile with the number of its
 * copies (a text search shows the copies too); its page lists them, a copy's page links its
 * original and offers Remake from original, the Edit image screen "Make a copy and edit it",
 * and the picker opens a stack (?stack={id}) to pick the original or a copy. Every change
 * goes through MediaLibrary; Folio only adds the UI.
 */
final class AssetLibraryController
{
    private const int PER_PAGE = 48;

    public function __construct(
        private readonly MediaLibrary $library,
        private readonly AssetUsage $usage,
        private readonly TemplateEngineInterface $engine,
        private readonly TypeCatalog $catalog,
        private readonly Locales $locales,
        private readonly string $prefix,
        private readonly FieldTypeRegistry $fieldTypes,
        private readonly MediaConfig $media,
        private readonly Formats $formats,
        private readonly AssetCleanup $cleanup,
    ) {}

    /** The image crop's ratios besides "Free" and the box presets' own: label => width / height. */
    private const array RATIOS = ['1:1' => 1.0, '4:3' => 4 / 3, '3:2' => 1.5, '16:9' => 16 / 9];

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderIndex($request, []);
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $picker = ($body['picker'] ?? '') === '1';
        $private = $picker ? self::privacy($body) : null;
        $by = self::by($request);

        $files = $request->getUploadedFiles()['files'] ?? [];
        $files = $files instanceof UploadedFileInterface ? [$files] : (is_array($files) ? $files : []);
        $created = [];
        $errors = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $created[] = $this->library->upload($file, $by, private: $private === true)->id;
            } catch (AssetUploadException $e) {
                $errors[] = MediaMessages::text($e->getMessage());
            }
        }

        if ($errors !== []) {
            return $this->renderIndex($request, $errors, 422, $created);
        }

        $location = $this->prefix . '/assets';
        if ($picker) {
            $location .= '?picker=1&multiple=' . (($body['multiple'] ?? '') === '1' ? '1' : '0')
                . ($private !== null ? '&private=' . ($private ? '1' : '0') : '') . '&selected=' . implode(',', $created);
        }

        return new Response(302, ['Location' => $location]);
    }

    public function panel(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }

        $query = $request->getQueryParams();
        $notice = match (true) {
            ($query['saved'] ?? null) === '1' => FolioText::get('folio.assets.saved'),
            ($query['done'] ?? null) === 'replaced' => FolioText::get('folio.assets.replaced'),
            ($query['done'] ?? null) === 'restored' => FolioText::get('folio.assets.restored', ['number' => (int) ($query['version'] ?? 0)]),
            ($query['done'] ?? null) === 'version-deleted' => FolioText::get('folio.assets.version_deleted'),
            ($query['done'] ?? null) === 'unbinned' => FolioText::get('folio.assets.unbinned'),
            ($query['done'] ?? null) === 'remade' => FolioText::get('folio.assets.remade'),
            default => null,
        };

        return $this->renderPanel($request, $asset, $notice);
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $body = (array) $request->getParsedBody();
        $meta = array_intersect_key($body, array_flip(['alt', 'title', 'focal_x', 'focal_y']));
        if ($asset->kind() === 'video') {
            $meta['poster'] = $body['poster'] ?? '';
        }
        // The panel's form carries every extra field (an unchecked box or an empty multi relation posts nothing).
        if (($body['_extra'] ?? null) === '1') {
            foreach ($this->extraFields() as $name => $field) {
                $meta[$name] = $this->fieldTypes->get($field->type)->normalizeInput($body[$name] ?? null, $field->config);
            }
        }
        $this->library->update($asset->id, $meta);

        return $this->backToPanel($request, $asset, 'saved=1');
    }

    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        try {
            $this->library->delete($asset->id);
        } catch (AssetInUseException $e) {
            return $this->renderPanel($request, $asset, null, self::inUse($e->usages), 409, $e->usages);
        } catch (\RuntimeException $e) {
            if ($this->library->binDir() === null) {
                throw $e;
            }
            // A file could not be moved into the bin (nothing changed).
            error_log('folio: could not move asset ' . $asset->id . ' into the bin: ' . $e::class . ': ' . $e->getMessage());

            return $this->renderPanel($request, $asset, null, FolioText::get('folio.assets.bin_failed', ['label' => $asset->label()]), 422);
        }
        $location = $this->back($request) ?? $this->prefix . '/assets';
        if ($this->library->binDir() !== null) {
            // A page the library was opened on after an earlier delete already says done=binned: once is enough.
            $location = preg_replace('/([?&])done=[^&]*(&|$)/', '$1', $location) ?? $location;
            $location = rtrim($location, '?&');
            $location .= (str_contains($location, '?') ? '&' : '?') . 'done=binned';
        }

        return new Response(302, ['Location' => $location]);
    }

    /**
     * Why a delete was refused: the records that use the file, then its copies (each copy is
     * a usage of its original, but people count them apart: they are on the Copies panel).
     *
     * @param list<AssetUsageEntry> $usages
     */
    private static function inUse(array $usages): string
    {
        $copies = count(array_filter($usages, static fn (AssetUsageEntry $u): bool => $u->note === CopyReferences::NOTE));
        $records = count($usages) - $copies;

        return implode(' ', array_filter([
            $records > 0 ? FolioText::choice('folio.assets.in_use', $records) : '',
            $copies > 0 ? FolioText::choice('folio.assets.in_use_copies', $copies) : '',
        ]));
    }

    /** {prefix}/assets/bin: what was deleted, the most recently deleted first, each with "Restore". */
    public function bin(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderBin($request, max(1, (int) ($request->getQueryParams()['page'] ?? 1)));
    }

    /** Brings a file back from the bin, then shows its page. */
    public function restoreFromBin(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->findBinned((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        try {
            $this->library->restoreFromBin($asset->id);
        } catch (\Throwable $e) {
            error_log('folio: could not restore asset ' . $asset->id . ' from the bin: ' . $e::class . ': ' . $e->getMessage());

            return $this->renderBin($request, 1, FolioText::get('folio.assets.restore_from_bin_failed', ['label' => $asset->label()]), 422);
        }

        return new Response(302, ['Location' => $this->prefix . '/assets/' . $asset->id . '?done=unbinned']);
    }

    private function renderBin(ServerRequestInterface $request, int $page, ?string $error = null, int $status = 200): ResponseInterface
    {
        $result = $this->library->binned($page, self::PER_PAGE);

        return $this->html($this->engine->render('@folio/admin/assets/bin.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'editor_assets' => ['admin.js'],
            'items' => array_map(fn (Asset $a) => ['binned_at' => $a->binnedAt, 'copy_of' => $this->originalLabel($a)] + $this->item($a), $result->items),
            'total' => $result->total,
            'page' => $result->page,
            'pages' => max(1, (int) ceil($result->total / self::PER_PAGE)),
            'has_bin' => $this->library->binDir() !== null,
            'bin_days' => $this->media->cleanup['bin_days'],
            'error' => $error,
            'csrf' => self::csrf($request),
        ]), $status);
    }

    /** The label of a copy's original, live or in the bin; null for an original, or a copy whose original is gone. */
    private function originalLabel(Asset $asset): ?string
    {
        if (!$asset->isCopy()) {
            return null;
        }
        $id = (string) $asset->derivedFrom;

        return ($this->library->find($id) ?? $this->library->findBinned($id))?->label();
    }

    /** A new file for the asset (same kind); the old one becomes a version. Errors show in the panel. */
    public function replace(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->renderPanel($request, $asset, null, FolioText::get('folio.assets.choose_file'), 422);
        }
        try {
            $this->library->replace($asset->id, $file, self::by($request));
        } catch (\Throwable $e) {
            return $this->failed($request, $asset, 'replace the file', $e, FolioText::get('folio.assets.replace_failed'));
        }

        return $this->backToPanel($request, $asset, 'done=replaced');
    }

    public function restoreVersion(ServerRequestInterface $request): ResponseInterface
    {
        [$asset, $version] = $this->version($request);
        if ($asset === null || $version === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        try {
            $this->library->restore($asset->id, $version->number, self::by($request));
        } catch (\Throwable $e) {
            return $this->failed($request, $asset, 'restore version ' . $version->number, $e, FolioText::get('folio.assets.restore_failed', ['number' => $version->number]));
        }

        return $this->backToPanel($request, $asset, 'done=restored&version=' . $version->number);
    }

    public function deleteVersion(ServerRequestInterface $request): ResponseInterface
    {
        [$asset, $version] = $this->version($request);
        if ($asset === null || $version === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        try {
            $this->library->deleteVersion($asset->id, $version->number);
        } catch (\Throwable $e) {
            return $this->failed($request, $asset, 'delete version ' . $version->number, $e, FolioText::get('folio.assets.delete_version_failed', ['number' => $version->number]));
        }

        return $this->backToPanel($request, $asset, 'done=version-deleted');
    }

    /** The Edit image screen: the turned and mirrored original in the cropper, and each box preset's cut. */
    public function editor(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null || !$this->library->isEditable($asset)) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        $query = $request->getQueryParams();
        $preset = is_string($query['preset'] ?? null) && isset($this->formats->boxes()[$query['preset']]) ? $query['preset'] : null;
        $notice = match ($query['done'] ?? null) {
            'rotated' => FolioText::get('folio.assets.rotated'),
            'mirrored' => FolioText::get('folio.assets.mirrored'),
            'cropped' => FolioText::get('folio.assets.cropped'),
            'crop-removed' => FolioText::get('folio.assets.crop_removed'),
            'format-cropped' => $preset !== null ? FolioText::get('folio.assets.format_cropped', ['preset' => $preset]) : null,
            'format-reset' => $preset !== null ? FolioText::get('folio.assets.format_reset', ['preset' => $preset]) : null,
            'copied' => FolioText::get('folio.assets.copied'),
            default => null,
        };

        return $this->renderEditor($request, $asset, $notice);
    }

    /**
     * One edit from the screen (op: rotate-left, rotate-right, flip-h, flip-v, crop, format-crop),
     * then back to it. What the core refuses is shown with its message (422).
     */
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        try {
            $done = $this->apply($asset->id, (array) $request->getParsedBody());
        } catch (\InvalidArgumentException $e) {
            return $this->renderEditor($request, $this->library->find($asset->id) ?? $asset, null, MediaMessages::text($e->getMessage()), 422);
        } catch (\Throwable $e) {
            error_log('folio: could not edit asset ' . $asset->id . ': ' . $e::class . ': ' . $e->getMessage());

            return $this->renderEditor($request, $this->library->find($asset->id) ?? $asset, null, FolioText::get('folio.assets.edit_failed'), 422);
        }

        return new Response(302, ['Location' => $this->prefix . '/assets/' . $asset->id . '/edit?done=' . $done . $this->backQuery($request, '&')]);
    }

    /**
     * "Make a copy and edit it" on the Edit image screen: a new copy (of an original: maker
     * "copy" with its edits; of a copy: a sibling), then the screen on the copy. The asset
     * itself stays as it is. Only editable images, like the screen.
     */
    public function makeCopy(ServerRequestInterface $request): ResponseInterface
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        if ($asset === null || !$this->library->isEditable($asset)) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        try {
            $copy = $this->library->makeCopy($asset->id, self::by($request));
        } catch (\Throwable $e) {
            $message = $e instanceof AssetUploadException ? MediaMessages::text($e->getMessage()) : FolioText::get('folio.assets.copy_failed');
            if (!$e instanceof AssetUploadException) {
                error_log('folio: could not copy asset ' . $asset->id . ': ' . $e::class . ': ' . $e->getMessage());
            }

            return $this->renderEditor($request, $asset, null, $message, 422);
        }

        return new Response(302, ['Location' => $this->prefix . '/assets/' . $copy->id . '/edit?done=copied' . $this->backQuery($request, '&')]);
    }

    /**
     * Remake from original: the copy made again from its original's current file, same id
     * (the earlier file becomes a version). Refused on the page when the core can't
     * (no maker registered, the original gone or in the bin, no versions_dir).
     */
    public function remake(ServerRequestInterface $request): ResponseInterface
    {
        $copy = $this->library->find((string) $request->getAttribute('id', ''));
        if ($copy === null || !$copy->isCopy()) {
            return new Response(404, [], FolioText::get('folio.errors.not_found'));
        }
        if (!$this->library->canRemake($copy)) {
            return $this->renderPanel($request, $copy, null, FolioText::get('folio.assets.cannot_remake'), 422);
        }
        try {
            $this->library->remake($copy->id, self::by($request));
        } catch (\Throwable $e) {
            return $this->failed($request, $copy, 'remake', $e, FolioText::get('folio.assets.remake_failed'));
        }

        return $this->backToPanel($request, $copy, 'done=remade');
    }

    /**
     * Calls the core for one edit of the screen.
     *
     * @param array<mixed> $body
     * @return string the screen's "done" query
     * @throws \InvalidArgumentException for an unknown op, a missing area, or what the core refuses
     */
    private function apply(string $id, array $body): string
    {
        $preset = is_string($body['preset'] ?? null) ? $body['preset'] : '';
        switch ($body['op'] ?? null) {
            case 'rotate-left':
            case 'rotate-right':
                $this->library->rotate($id, $body['op'] === 'rotate-left' ? -1 : 1);
                return 'rotated';
            case 'flip-h':
            case 'flip-v':
                $this->library->flip($id, $body['op'] === 'flip-h');
                return 'mirrored';
            case 'crop':
                $clear = ($body['clear'] ?? null) === '1';
                $this->library->setCrop($id, $clear ? null : self::rect($body));
                return $clear ? 'crop-removed' : 'cropped';
            case 'format-crop':
                $reset = ($body['reset'] ?? null) === '1';
                $this->library->setFormatCrop($id, $preset, $reset ? null : self::rect($body));
                return ($reset ? 'format-reset' : 'format-cropped') . '&preset=' . rawurlencode($preset);
            default:
                throw new \InvalidArgumentException(FolioText::get('folio.assets.unknown_edit'));
        }
    }

    /**
     * The area of a crop form (x, y, w, h in percent).
     *
     * @param array<mixed> $body
     * @throws \InvalidArgumentException when it is missing (no area drawn, or no JavaScript)
     */
    private static function rect(array $body): CropRect
    {
        foreach (['x', 'y', 'w', 'h'] as $key) {
            if (!is_numeric($body[$key] ?? null)) {
                throw new \InvalidArgumentException(FolioText::get('folio.assets.draw_first'));
            }
        }

        return CropRect::of((float) $body['x'], (float) $body['y'], (float) $body['w'], (float) $body['h']);
    }

    private function renderEditor(ServerRequestInterface $request, Asset $asset, ?string $notice, ?string $error = null, int $status = 200): ResponseInterface
    {
        if (!$this->library->isEditable($asset)) {
            // Only an edit can get here with such an asset (the screen itself is a 404): the panel says why.
            return $this->renderPanel($request, $asset, null, $error ?? FolioText::get('folio.assets.not_editable'), 422);
        }
        $boxes = $this->formats->boxes();
        $ratios = [FolioText::get('folio.assets.free') => ''];
        foreach (self::RATIOS as $label => $ratio) {
            $ratios[$label] = self::ratio($ratio);
        }
        foreach ($boxes as $format) {
            $ratios[$format->name . ' (' . $format->width . '×' . $format->height . ')'] = self::ratio((float) $format->ratio());
        }
        return $this->html($this->engine->render('@folio/admin/assets/edit.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'editor_assets' => ['admin.js', 'cropper.css', 'image-editor.css', 'cropper.js', 'image-editor.js'],
            'asset' => $this->item($asset),
            'copy_of' => $this->originalLabel($asset),
            'oriented' => $this->library->orientedUrl($asset),
            'crop' => $asset->crop?->toArray(),
            'edited' => $asset->isEdited(),
            'delivered' => self::size($this->library->deliveryAsset($asset) ?? $asset),
            'ratios' => $ratios,
            'formats' => array_values(array_map(fn (Format $f) => $this->formatRow($asset, $f), $boxes)),
            'notice' => $notice,
            'error' => $error,
            'csrf' => self::csrf($request),
        ] + $this->backContext($request)), $status);
    }

    /** @return array<string, mixed> one box preset on the screen: its box, its cut now and (cover) its region */
    private function formatRow(Asset $asset, Format $format): array
    {
        $plan = $this->library->boxPlan($asset, $format->name);
        $region = $format->fit === 'cover' ? $this->library->formatRegion($asset, $format->name) : null;

        return [
            'name' => $format->name,
            'width' => $format->width,
            'height' => $format->height,
            'fit' => $format->fit,
            'ratio' => self::ratio((float) $format->ratio()),
            'cut' => $plan !== null ? $this->library->url($plan->base . '-' . $plan->srcWidth() . '.webp') : null,
            'cut_width' => $plan?->width,
            'cut_height' => $plan?->height,
            'region' => $region?->toArray(),
            'adjustable' => $format->fit === 'cover',
            'own' => isset($asset->crops[$format->name]),
        ];
    }

    /** A ratio as the screen's select and data attributes carry it (4 decimals). */
    private static function ratio(float $ratio): string
    {
        return number_format($ratio, 4, '.', '');
    }

    /**
     * A failed file change as a panel error, never a 500: an upload error's message as it is
     * (it names the file and the reason), anything else logged and reported in general words.
     */
    private function failed(ServerRequestInterface $request, Asset $asset, string $action, \Throwable $e, string $message): ResponseInterface
    {
        if ($e instanceof AssetUploadException) {
            return $this->renderPanel($request, $asset, null, MediaMessages::text($e->getMessage()), 422);
        }
        error_log('folio: could not ' . $action . ' of asset ' . $asset->id . ': ' . $e::class . ': ' . $e->getMessage());
        $current = $this->library->find($asset->id) ?? $asset;

        return $this->renderPanel($request, $current, null, $message, 422);
    }

    /** @return array{?Asset, ?AssetVersion} the asset and its version {n} from the route */
    private function version(ServerRequestInterface $request): array
    {
        $asset = $this->library->find((string) $request->getAttribute('id', ''));
        $n = (string) $request->getAttribute('n', '');
        if ($asset === null || preg_match('/^[1-9][0-9]{0,5}$/', $n) !== 1) {
            return [$asset, null];
        }
        foreach ($this->library->versions($asset->id) as $version) {
            if ($version->number === (int) $n) {
                return [$asset, $version];
            }
        }

        return [$asset, null];
    }

    private function backToPanel(ServerRequestInterface $request, Asset $asset, string $query): ResponseInterface
    {
        return new Response(302, ['Location' => $this->prefix . '/assets/' . $asset->id . '?' . $query . $this->backQuery($request, '&')]);
    }

    /**
     * The library page the details were opened from ("back" in the query, or a form's body): only
     * a relative {prefix}/assets URL, so it can't send anyone elsewhere (no "//", backslash, scheme
     * or control character). Null when absent or refused.
     */
    private function back(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        $back = $request->getQueryParams()['back'] ?? (is_array($body) ? ($body['back'] ?? null) : null);
        $root = $this->prefix . '/assets';
        if (!is_string($back) || !str_starts_with($back, $root)) {
            return null;
        }
        $next = $back[strlen($root)] ?? '';
        if (($next !== '' && $next !== '?') || preg_match('/[\\\\\x00-\x1f\x7f:]|\/\//', $back) === 1) {
            return null;
        }

        return $back;
    }

    /** "{glue}back=…" for links and redirects that keep the library page; empty without one. */
    private function backQuery(ServerRequestInterface $request, string $glue): string
    {
        $back = $this->back($request);

        return $back === null ? '' : $glue . 'back=' . rawurlencode($back);
    }

    /**
     * @param list<string> $errors
     * @param list<string> $selected
     */
    private function renderIndex(ServerRequestInterface $request, array $errors, int $status = 200, array $selected = []): ResponseInterface
    {
        $params = array_merge($request->getQueryParams(), (array) $request->getParsedBody());
        $q = trim(is_string($params['q'] ?? null) ? $params['q'] : '');
        $kind = in_array($params['kind'] ?? null, ['image', 'video', 'document'], true) ? $params['kind'] : '';
        $view = ($params['view'] ?? '') === 'list' ? 'list' : 'grid';
        $picker = ($params['picker'] ?? '') === '1';
        $multiple = ($params['multiple'] ?? '') === '1';
        $private = $picker ? self::privacy($params) : null; // a field's picker lists only what the field accepts
        $page = max(1, (int) ($params['page'] ?? 1));
        if ($selected === [] && is_string($params['selected'] ?? null)) {
            $selected = array_values(array_filter(explode(',', $params['selected'])));
        }

        $filters = $this->filterValues($params['f'] ?? null);
        $controls = $this->filterControls($filters);
        $editorAssets = $picker ? ['admin.js'] : ['admin.js', 'htmx.js'];
        foreach ($controls as $control) {
            $editorAssets = [...$editorAssets, ...$control['assets']]; // a remote relation editor's own assets
        }

        $unused = !$picker && $this->cleanup->isEnabled() && ($params['unused'] ?? '') === '1';
        $stack = $picker && is_string($params['stack'] ?? null) ? $this->library->find($params['stack']) : null;
        $stack = $stack !== null && !$stack->isCopy() ? $stack : null;
        $result = match (true) {
            $stack !== null => $this->stack($stack, $private),
            $unused => $this->unused($kind, $q, $page),
            default => $this->library->query($kind === '' ? null : $kind, $q, $filters + ($private === null ? [] : ['private' => $private]), $page, self::PER_PAGE),
        };
        $total = $result->total;
        $pages = $stack !== null ? 1 : max(1, (int) ceil($total / self::PER_PAGE));
        $page = $result->page;
        // An opened stack shows no bubbles: its original's copies are the tiles.
        $counts = $stack === null ? $this->library->copyCounts(array_map(static fn (Asset $a) => $a->id, $result->items)) : [];
        $query = array_filter(['q' => $q, 'kind' => $kind, 'f' => $filters, 'unused' => $unused ? '1' : '', 'picker' => $picker ? '1' : '', 'multiple' => $picker ? ($multiple ? '1' : '0') : '', 'private' => $private === null ? '' : ($private ? '1' : '0')], static fn ($v) => $v !== '');

        return $this->html($this->engine->render('@folio/admin/assets/index.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'layout' => $picker ? '@folio/admin/_drawer_layout.twig' : '@folio/admin/_layout.twig',
            'editor_assets' => array_values(array_unique($editorAssets)),
            'items' => array_map(fn (Asset $a) => ['copies' => $counts[$a->id] ?? 0] + $this->item($a), $result->items),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'stack' => $stack !== null ? $this->item($stack) : null,
            'unstacked' => $query,
            'q' => $q,
            'kind' => $kind,
            'filters' => $controls,
            'filtered' => $filters !== [],
            'unused' => $unused,
            'cleanup' => !$picker && $this->cleanup->isEnabled(),
            'min_age_days' => $this->media->cleanup['min_age_days'],
            'no_bin' => !$picker && $this->library->binDir() === null,
            'has_bin' => $this->library->binDir() !== null,
            'notice' => ($params['done'] ?? null) === 'binned' ? FolioText::get('folio.assets.binned') : null,
            'view' => $view,
            'picker' => $picker,
            'multiple' => $multiple,
            'private' => $private,
            'selected' => $selected,
            'errors' => $errors,
            'csrf' => self::csrf($request),
            'detail_q' => '?back=' . rawurlencode($this->prefix . '/assets?' . http_build_query(array_filter(['q' => $q, 'kind' => $kind, 'f' => $filters, 'unused' => $unused ? '1' : '', 'view' => $view, 'page' => (string) $page], static fn ($v) => $v !== '' && $v !== []))),
            'query' => $query + ($stack !== null ? ['stack' => $stack->id] : []),
        ]), $status);
    }

    /**
     * One page of what cleanup would bin (AssetCleanup::candidates(): unused, uploaded longer
     * ago than min_age_days), oldest upload first, narrowed by kind and the search words (each
     * in the name, title or an alt text). The toolbar's extra filters don't apply here.
     * The usage sources are asked about every old enough asset on each request and the
     * result is paged here, so pages are exact (cleanup's own chunks count checked assets,
     * not candidates); on a very large library this page is slow, like media:cleanup.
     */
    private function unused(string $kind, string $q, int $page): AssetPage
    {
        $words = preg_split('/\s+/', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $assets = [];
        foreach ($this->cleanup->candidates($kind === '' ? [] : ['kind' => $kind]) as $candidate) {
            $asset = $candidate->asset;
            $text = mb_strtolower($asset->originalName . ' ' . $asset->title . ' ' . implode(' ', $asset->alt));
            if (array_filter($words, static fn (string $w): bool => !str_contains($text, $w)) === []) {
                $assets[] = $asset;
            }
        }
        $pages = max(1, (int) ceil(count($assets) / self::PER_PAGE));
        $page = min($page, $pages);

        return new AssetPage(array_slice($assets, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($assets), $page, self::PER_PAGE);
    }

    /**
     * An opened stack in the picker: the original, then its copies (newest first), those
     * the field accepts (its private filter), on one page.
     */
    private function stack(Asset $original, ?bool $private): AssetPage
    {
        $assets = array_values(array_filter(
            [$original, ...$this->library->copies($original->id)],
            static fn (Asset $a): bool => $private === null || $a->private === $private,
        ));

        return new AssetPage($assets, count($assets), 1, max(1, count($assets)));
    }

    /** @return array{back: string, back_q: string} the "← Assets" target, and "?back=…" (or '') to carry on links */
    private function backContext(ServerRequestInterface $request): array
    {
        return ['back' => $this->back($request) ?? $this->prefix . '/assets', 'back_q' => $this->backQuery($request, '?')];
    }

    /** @param list<AssetUsageEntry>|null $usage */
    private function renderPanel(ServerRequestInterface $request, Asset $asset, ?string $notice, ?string $error = null, int $status = 200, ?array $usage = null): ResponseInterface
    {
        $posters = $asset->kind() === 'video'
            ? array_values(array_filter($this->library->all(), static fn (Asset $a) => $a->kind() === 'image'))
            : [];
        [$fields, $fieldAssets] = $this->extraEditors($asset);
        $context = [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'section' => 'assets',
            'editor_assets' => array_values(array_unique(['admin.js', ...$fieldAssets])),
            'asset' => $this->item($asset),
            'fields' => $fields,
            'versions' => array_map(fn (AssetVersion $v) => $this->versionRow($asset, $v), $this->library->versions($asset->id)),
            'swatches' => self::swatches($asset),
            'note' => MediaMessages::note($asset->note),
            'raw' => $asset,
            'editable' => $this->library->isEditable($asset),
            'edited' => $asset->kind() === 'image' && $asset->isEdited(),
            'original_url' => $this->library->originalUrl($asset),
            'delivered' => self::size($this->library->deliveryAsset($asset) ?? $asset),
            'locales' => $this->locales->available,
            'posters' => array_map(fn (Asset $a) => $this->item($a), $posters),
            'usage' => array_map($this->usageRow(...), $usage ?? $this->usage->of($asset->id)),
            'copies' => $asset->isCopy() ? [] : array_map($this->copyRow(...), $this->library->copies($asset->id)),
            'copy' => $asset->isCopy() ? $this->copyInfo($asset) : null,
            'has_bin' => $this->library->binDir() !== null,
            'notice' => $notice,
            'error' => $error,
            'csrf' => self::csrf($request),
        ] + $this->backContext($request);

        return $this->html($this->engine->render('@folio/admin/assets/panel_page.twig', $context), $status);
    }

    /** @return array<string, mixed> a row of an original's Copies panel: the copy, its maker, where it is used, whether the original changed since */
    private function copyRow(Asset $copy): array
    {
        return [
            'maker' => $this->makerLabel($copy),
            'stale' => $this->library->isStale($copy),
            'usage' => array_map($this->usageRow(...), $this->usage->of($copy->id)),
        ] + $this->item($copy);
    }

    /**
     * What a copy's page says about its original: the original (null when it is gone or in
     * the bin; then its label from the bin), the maker, whether it changed since, whether
     * Remake from original can run.
     *
     * @return array{original: ?array<string, mixed>, binned_original: ?string, maker: string, stale: bool, can_remake: bool}
     */
    private function copyInfo(Asset $copy): array
    {
        $original = $this->library->find((string) $copy->derivedFrom);

        return [
            'original' => $original !== null ? $this->item($original) : null,
            'binned_original' => $original === null ? $this->library->findBinned((string) $copy->derivedFrom)?->label() : null,
            'maker' => $this->makerLabel($copy),
            'stale' => $this->library->isStale($copy),
            'can_remake' => $this->library->canRemake($copy),
        ];
    }

    /** How a copy was made, for people: "plain copy" for Folio's own, a maker's name as registered, or "unknown". */
    private function makerLabel(Asset $copy): string
    {
        return match ($copy->maker) {
            MediaLibrary::MAKER_COPY => FolioText::get('folio.assets.maker_copy'),
            null => FolioText::get('folio.assets.maker_unknown'),
            default => $copy->maker,
        };
    }

    /**
     * A usage entry as the panel template's row: where it is ("working",
     * "published" and "revision" from Folio's notes, "poster" and "binned_poster"
     * from the core's poster source, "copy" from its copy source; other sources'
     * entries count as working copies), and whether it blocks a delete: without bin_dir every
     * usage does, as the library refuses a delete for good under any usage (an earlier revision
     * would lose its file). The template links the poster of a binned file to the bin for those
     * who may open it (assets.delete).
     *
     * @return array{label: string, url: string, where: string, source: string, note: ?string, blocks: bool}
     */
    private function usageRow(AssetUsageEntry $entry): array
    {
        $where = match ($entry->note) {
            FolioRecordReferences::PUBLISHED => 'published',
            FolioRecordReferences::REVISION => 'revision',
            PosterReferences::NOTE => 'poster',
            PosterReferences::NOTE_BINNED => 'binned_poster',
            CopyReferences::NOTE => 'copy',
            default => 'working',
        };

        return [
            'label' => $entry->label,
            'url' => $entry->url ?? '',
            'where' => $where,
            'source' => $entry->source,
            'note' => $entry->note,
            'blocks' => $entry->blocksDelete || $this->library->binDir() === null,
        ];
    }

    /** @return array<string, TypeFieldDefinition> the extra fields of config/media.php */
    private function extraFields(): array
    {
        return array_filter(
            $this->library->definition()->fields,
            static fn (TypeFieldDefinition $f) => !in_array($f->name, Asset::RESERVED, true),
        );
    }

    /**
     * The extra fields' editors, rendered by Folio's field types as in the record form,
     * and the admin assets those types need.
     *
     * @return array{list<string>, list<string>}
     */
    private function extraEditors(Asset $asset): array
    {
        $html = [];
        $assets = [];
        foreach ($this->extraFields() as $name => $field) {
            $type = $this->fieldTypes->get($field->type);
            $html[] = $type->renderEditor(new FieldContext(
                name: $name,
                label: \Preflow\Folio\Content\DisplayLabels::pick($field->labels ?: $field->label) ?? ucfirst(str_replace('_', ' ', $name)),
                help: \Preflow\Folio\Content\DisplayLabels::pick($field->helps ?: $field->help),
                value: $type->fromStorage($asset->extra[$name] ?? null),
                config: $field->config,
                required: in_array('required', $field->validate, true),
                locale: $this->locales->default,
                locales: $this->locales,
            ));
            $assets = [...$assets, ...$type->assets()];
        }

        return [$html, $assets];
    }

    /**
     * The toolbar's values: config "filters" names from the request's f[…], text only.
     *
     * @return array<string, string>
     */
    private function filterValues(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($this->media->filters as $name) {
            $value = $raw[$name] ?? null;
            if (is_string($value) && $value !== '' && strlen($value) <= 255) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * One toolbar select per configured filter: a select field's options, a relation's
     * targets (as its field type lists them; a large target searches instead) or yes/no.
     *
     * @param array<string, string> $values
     * @return list<array{name: string, label: string, value: string, options: array<string, string>, html: ?string, assets: list<string>}>
     */
    private function filterControls(array $values): array
    {
        $fields = $this->extraFields();
        $out = [];
        foreach ($this->media->filters as $name) {
            $field = $fields[$name] ?? null;
            if ($field === null) {
                continue;
            }
            $label = \Preflow\Folio\Content\DisplayLabels::pick($field->labels ?: $field->label) ?? ucfirst(str_replace('_', ' ', $name));
            $value = $values[$name] ?? '';
            $options = [];
            $html = null;
            $assets = [];
            if ($field->type === 'boolean') {
                $options = ['1' => FolioText::get('folio.common.yes'), '0' => FolioText::get('folio.common.no')];
            } elseif ($field->type === 'select') {
                $options = \Preflow\Folio\Content\ListView::selectOptions($field->config);
            } elseif ($field->type === 'relation') {
                $type = $this->fieldTypes->get('relation');
                $config = ['relation' => ['multiple' => false] + (is_array($field->config['relation'] ?? null) ? $field->config['relation'] : [])];
                $choices = $type instanceof RelationFieldType ? $type->choices($config) : null;
                if ($choices === null) {
                    $html = $type->renderEditor(new FieldContext(name: 'f[' . $name . ']', label: $label, value: $value, config: $config, locale: $this->locales->default, locales: $this->locales));
                    $assets = $type->assets();
                } else {
                    $options = $choices;
                }
            }
            $out[] = ['name' => $name, 'label' => $label, 'value' => $value, 'options' => $options, 'html' => $html, 'assets' => $assets];
        }

        return $out;
    }

    /** @return array<string, mixed> a version list row; its file is the core's gated {private_url}/{id}/v/{n} */
    private function versionRow(Asset $asset, AssetVersion $version): array
    {
        return [
            'number' => $version->number,
            'created_at' => $version->createdAt,
            'size' => $version->size,
            'width' => $version->width,
            'height' => $version->height,
            'mime' => $version->mime,
            'url' => rtrim($this->media->privateUrl, '/') . '/' . $asset->id . '/v/' . $version->number,
        ];
    }

    /**
     * The dominant colour and the palette as swatches (hex and "rgb(r, g, b)" to copy); [] without colours.
     *
     * @return list<array{label: string, hex: string, rgb: string}>
     */
    private static function swatches(Asset $asset): array
    {
        $data = ColorRules::data($asset);
        if ($data === null) {
            return [];
        }
        $rgb = static fn (string $triple): string => 'rgb(' . str_replace(',', ', ', $triple) . ')';
        $out = [['label' => FolioText::get('folio.assets.dominant'), 'hex' => $data['color'], 'rgb' => $rgb($data['rgb'])]];
        foreach ($data['palette'] as $i => $hex) {
            $out[] = ['label' => FolioText::get('folio.assets.palette', ['number' => $i + 1]), 'hex' => $hex, 'rgb' => $rgb($data['palette_rgb'][$i])];
        }

        return $out;
    }

    /** @return array{width: ?int, height: ?int} */
    private static function size(Asset $asset): array
    {
        return ['width' => $asset->width, 'height' => $asset->height];
    }

    private static function by(ServerRequestInterface $request): ?string
    {
        $user = $request->getAttribute(\Preflow\Auth\Authenticatable::class);

        return $user instanceof \Preflow\Auth\Authenticatable ? $user->getAuthId() : null;
    }

    /** @return array<string, mixed> */
    private function item(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'label' => $asset->label(),
            'name' => $asset->originalName,
            'kind' => $asset->kind(),
            'mime' => $asset->mime,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $this->library->url($asset),
            'thumb' => $this->library->thumbnailUrl($asset),
            'alt' => $asset->alt,
            'title' => $asset->title,
            'focal_x' => $asset->focalX,
            'focal_y' => $asset->focalY,
            'poster' => $asset->posterId,
            'created_at' => $asset->createdAt,
            'copy' => $asset->isCopy(),
        ];
    }

    /**
     * The picker's "private" parameter: true or false, null when absent (any).
     *
     * @param array<mixed> $params
     */
    private static function privacy(array $params): ?bool
    {
        return match ($params['private'] ?? null) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    private static function csrf(ServerRequestInterface $request): string
    {
        return $request->getAttribute(\Preflow\Core\Http\Csrf\CsrfToken::class)?->getValue() ?? '';
    }

    private function html(string $html, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
