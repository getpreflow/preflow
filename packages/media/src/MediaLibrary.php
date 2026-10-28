<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\QueryBuilder;
use Preflow\Data\SortDirection;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Media\Color\ColorExtractor;
use Preflow\Media\Derived\DerivedMakers;
use Preflow\Media\Image\BoxPlan;
use Preflow\Media\Image\Format;
use Preflow\Media\Image\Formats;
use Preflow\Media\Image\Geometry;
use Preflow\Media\Image\Orientation;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The shared media library. Files live in a public directory served by the web
 * server ({dir}/Y/m/{id}.{ext}); their metadata are records of a code-defined
 * type (key "_asset", table "folio_assets"). The extension always follows the
 * sniffed MIME type, never the client's file name, and only allowed types are
 * stored, so nothing executable can land in the public directory.
 *
 * Private assets live in config "private_dir", outside the public directory,
 * without variants; their only URL is {private_url}/{id}, which a gated
 * controller serves.
 *
 * Replacing keeps the asset (id, alt, focal point, …) and swaps its file: the new one
 * gets a versioned name ({id}-v{n}.{ext}), the old one is kept as a version in config
 * "versions_dir" ({versions_dir}/{id}/v{n}.{ext}, never served by the web server;
 * only the gated {private_url}/{id}/v/{n}). Restore is a replace from a version.
 *
 * A document or video a preview renderer supports (a PDF: its first page; a video:
 * one frame) gets a preview on upload, replace and restore: {stem}-preview.png beside
 * its file, in the private dir for a private asset (served only at the gated
 * {private_url}/{id}/preview). A public document's or video's variants are its
 * preview's ({stem}-preview-{w}.{fmt}). An image poster picked for it wins over the
 * preview wherever it is shown (thumbnailUrl(), ImageTag).
 *
 * With a colour extractor, an image's colours (dominant colour, palette, text colour)
 * are read on upload, replace and restore from the image as people see it (sRGB),
 * a document's from its preview. A failure leaves the asset without colours.
 *
 * A public JPEG, PNG, WebP or AVIF image can be edited without touching its file:
 * rotation, mirroring and an image crop (setCrop, rotate, flip) give it a rendered
 * master ({stem}-edit-{key}.{ext}), which pages get instead of the file (deliveryAsset,
 * url) together with its variants and colours; originalUrl stays the untouched file.
 * Per-format crops (setFormatCrop) only change the box files of a cover preset.
 *
 * Deleting refuses an asset that a registered usage source reports with a usage that
 * blocks a delete (AssetInUseException), so every UI gets the rule. With config
 * "bin_dir" a delete moves the asset into the bin ({bin_dir}/{id}/, never served):
 * the record stays, marked binned_at, and find(), all() and query() no longer see
 * it; restoreFromBin() brings it back, emptyBin() deletes it for good later.
 * Without bin_dir a delete removes it for good.
 */
final class MediaLibrary
{
    /** Allowed MIME types and the extension each is stored with. */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'image/svg+xml' => 'svg',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/zip' => 'zip',
        'video/quicktime' => 'mov',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    /** Images above this many pixels are kept but get no variants (decoding them could exhaust memory). */
    private const int DEFAULT_MAX_PIXELS = 30_000_000;

    public const string ID_PATTERN = '/^[a-f0-9]{32}$/';

    /** The note on an image that needs colour management where it is missing. */
    public const string NOTE_NEEDS_IMAGICK = 'needs Imagick for colour-accurate variants';

    /** A preview's long side in pixels (less when max_pixels would not allow a square of it). */
    public const int PREVIEW_LONG_SIDE = 1600;

    /** What a version keeps of the asset besides its file, and what a restore brings back. */
    private const array SNAPSHOT = ['focal_x', 'focal_y', 'rotation', 'flip', 'crop', 'crops'];

    /** The maker of a plain copy (makeCopy() of an original): the original's file, unchanged. */
    public const string MAKER_COPY = 'copy';

    /** The image types that can be edited (rotated, mirrored, cropped). */
    private const array EDITABLE = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    /** The rendered master keeps the original's format; AVIF becomes WebP. */
    private const array MASTER_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'webp'];

    /** The long side of the editor's image (the turned and mirrored original without the crop). */
    public const int ORIENTED_LONG_SIDE = 1600;

    /** The folder under versions_dir with one lock file per asset ({id}.lock). */
    private const string LOCKS = '.locks';

    private ?TypeDefinition $definition = null;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $now;

    /**
     * @param array<string, mixed> $config MediaConfig::toArray() (storage, max_bytes, allow_svg, private_dir, private_url, …)
     */
    public function __construct(
        private readonly DataManager $dm,
        private readonly Locales $locales,
        private readonly string $dir,
        private readonly string $url,
        private readonly array $config = [],
        private readonly ?VariantGenerator $variants = null,
        ?\Closure $now = null,
        private readonly ?AssetUsage $usage = null,
        private readonly ?PreviewRenderers $previews = null,
        private readonly ?ColorExtractor $colors = null,
        private readonly ?Formats $formats = null,
        private readonly ?DerivedMakers $makers = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    public function definition(): TypeDefinition
    {
        if ($this->definition !== null) {
            return $this->definition;
        }
        $fields = [];
        foreach (Asset::TEXT_COLUMNS as $name) {
            $fields[$name] = new TypeFieldDefinition($name, 'string', translatable: $name === 'alt');
        }
        $fields['private'] = new TypeFieldDefinition('private', 'boolean'); // missing on older records: public
        $fields['derived_version'] = new TypeFieldDefinition('derived_version', 'number'); // a copy's original file version; NULL for an original
        // The site's extra fields (config/media.php "fields"): a column each, so the table step adds them and queries filter on them.
        $fields += $this->extraFields();

        return $this->definition = new TypeDefinition(
            key: '_asset',
            table: 'folio_assets',
            storage: (string) ($this->config['storage'] ?? 'json'),
            fields: $fields,
            idField: 'uuid',
            locales: $this->locales,
        );
    }

    /**
     * The extra fields as type fields, from config "fields" (model field shape). A name of an asset's own key never gets here (boot error).
     *
     * @return array<string, TypeFieldDefinition>
     */
    private function extraFields(): array
    {
        $fields = [];
        foreach ((array) ($this->config['fields'] ?? []) as $name => $schema) {
            if (!is_string($name) || !is_array($schema) || in_array($name, Asset::RESERVED, true)) {
                continue;
            }
            $fields[$name] = new TypeFieldDefinition(
                name: $name,
                type: is_string($schema['type'] ?? null) ? $schema['type'] : 'string',
                searchable: isset($schema['searchable']) ? (bool) $schema['searchable'] : null,
                validate: array_values(array_filter((array) ($schema['validate'] ?? []), 'is_string')),
                label: is_string($schema['label'] ?? null) ? $schema['label'] : null,
                help: is_string($schema['help'] ?? null) ? $schema['help'] : null,
                config: array_diff_key($schema, array_flip(['type', 'searchable', 'transform', 'validate', 'label', 'help', 'translatable'])),
            );
        }

        return $fields;
    }

    /**
     * A value for an extra field in the shape its type stores: a boolean, a list of ids for a multiple
     * relation, one of the options for a select (else empty), else text.
     */
    private static function extraValue(TypeFieldDefinition $field, mixed $value): mixed
    {
        if ($field->type === 'boolean') {
            return in_array($value, [true, 1, '1', 'on', 'true'], true);
        }
        if ($field->type === 'relation') {
            $ids = array_values(array_unique(array_filter(
                array_map(static fn ($id) => is_string($id) || is_int($id) ? trim((string) $id) : '', is_array($value) ? $value : [$value]),
                static fn (string $id) => $id !== '',
            )));
            if (($field->config['relation']['multiple'] ?? false) === true) {
                return $ids;
            }

            return $ids[0] ?? '';
        }
        if ($field->type === 'select') {
            $options = $field->config['options'] ?? null;
            $allowed = is_array($options) ? array_map('strval', array_is_list($options) ? $options : array_keys($options)) : null;
            $value = is_scalar($value) ? (string) $value : '';

            return $allowed === null || in_array($value, $allowed, true) ? $value : '';
        }
        if (in_array($field->type, ['number', 'int', 'integer', 'float'], true)) {
            return is_numeric($value) ? $value + 0 : '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The extra values a new asset starts with (false, no ids, empty text).
     *
     * @return array<string, mixed>
     */
    private function extraDefaults(): array
    {
        return array_map(static fn (TypeFieldDefinition $f) => self::extraValue($f, null), $this->extraFields());
    }

    /**
     * @param bool $private store in the private directory, served only through the gated URL
     * @throws AssetUploadException
     */
    public function upload(UploadedFileInterface $file, ?string $by, bool $private = false): Asset
    {
        [$bytes, $name] = $this->readUpload($file);

        return $this->store($bytes, $name, $by, $private);
    }

    /**
     * Copy a file on disk into the library (the source stays where it is).
     *
     * @param bool $private store in the private directory, served only through the gated URL
     * @throws AssetUploadException
     */
    public function importFile(string $absPath, string $originalName, ?string $by, bool $private = false): Asset
    {
        return $this->store($this->readFile($absPath, $originalName), $originalName, $by, $private);
    }

    /**
     * Store a file on disk (which stays where it is) as a copy of an original: a full asset of
     * its own that knows its original, how it was made ($maker, e.g. "watermark") and with which
     * settings ($params). A stack is one original and its copies, so the id of a copy stands for
     * its original. The copy is private when the original is, unless $private says otherwise.
     *
     * @param array<string, mixed> $params
     * @throws AssetUploadException
     * @throws \InvalidArgumentException for an unknown original
     */
    public function derive(string $originalId, string $file, ?string $by, ?string $maker = null, array $params = [], ?bool $private = null): Asset
    {
        $original = $this->originalOf($originalId);

        return $this->store($this->readFile($file, $original->originalName), $original->originalName, $by, $private ?? $original->private, [
            'derived_from' => $original->id,
            'maker' => $maker ?? '',
            'maker_params' => $params,
            'derived_version' => $original->version,
        ]);
    }

    /**
     * A new copy to edit on its own. Of an original: its file as uploaded, starting with its
     * crop, rotation, flip, format crops and focal point (maker "copy"). Of a copy: a sibling
     * (same original, maker and settings) with that copy's file and edits, so a watermarked
     * copy's copy is watermarked too. The copy's own edited image is made like any edit's.
     *
     * @throws AssetUploadException
     * @throws \InvalidArgumentException for an unknown asset
     */
    public function makeCopy(string $id, ?string $by): Asset
    {
        $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        // The record and its file read together: a replace running now cannot swap the file in between.
        [$source, $bytes] = $this->guarded($id, function () use ($id): array {
            $source = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
            $file = $this->file($source);
            if ($file === null || !is_file($file)) {
                throw new AssetUploadException("{$source->originalName}: the file of asset {$id} is missing.");
            }

            return [$source, (string) file_get_contents($file)];
        });
        $original = $source->isCopy() ? $this->originalOf($source->id) : $source;
        $data = $source->toData();
        $fields = array_intersect_key($data, array_flip(self::SNAPSHOT)) + [
            'derived_from' => $original->id,
            'maker' => $source->isCopy() ? $source->maker ?? '' : self::MAKER_COPY,
            'maker_params' => $source->makerParams,
            'derived_version' => $source->isCopy() ? $source->derivedVersion : $source->version,
        ];

        return $this->store($bytes, $source->originalName, $by, $source->private, $fields);
    }

    /**
     * The copies of an original, newest first; a copy in the bin is not one of them.
     *
     * @return list<Asset>
     */
    public function copies(string $originalId): array
    {
        if (preg_match(self::ID_PATTERN, $originalId) !== 1) {
            return [];
        }
        $def = $this->definition();
        $records = $this->live($this->dm->queryIn($def))
            ->where('derived_from', '=', $originalId)
            ->orderBy('created_at', SortDirection::Desc)->orderBy($def->idField, SortDirection::Desc)
            ->get();

        return array_map(Asset::fromRecord(...), array_values($records->items()));
    }

    /**
     * How many copies each of the ids has (0 for none; copies in the bin do not count), in one query.
     *
     * @param list<string> $ids
     * @return array<string, int>
     */
    public function copyCounts(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => is_string($id) && preg_match(self::ID_PATTERN, $id) === 1)));
        if ($ids === []) {
            return [];
        }
        $counts = array_fill_keys($ids, 0);
        $copies = $this->live($this->dm->queryIn($this->definition()))->whereAny([['field' => 'derived_from', 'operator' => 'IN', 'value' => $ids]])->get();
        foreach ($copies->items() as $record) {
            $from = (string) ($record->toArray()['derived_from'] ?? '');
            if (isset($counts[$from])) {
                $counts[$from]++;
            }
        }

        return $counts;
    }

    /**
     * Make a copy again from its original, in place: same id, the earlier file becomes a
     * version (the replace() path, so versions_dir is needed), everything else stays (alt,
     * title, its own edits). A plain copy (maker "copy") takes the original's file as
     * uploaded and keeps its own crop, rotation and flip; any other maker gets the original
     * as people see it (turned and mirrored, without the crop, at full size). The copy then
     * counts as made from the original's current version (isStale() is false).
     *
     * @param ?array<string, mixed> $params new settings for the maker, stored on the copy (null: the copy's own)
     * @throws \InvalidArgumentException for an unknown asset, an original, a maker nobody registered, or an original that is gone or in the bin (nothing changed)
     * @throws AssetUploadException (nothing changed)
     */
    public function remake(string $copyId, ?string $by, ?array $params = null): Asset
    {
        $copy = $this->find($copyId) ?? throw new \InvalidArgumentException("Unknown asset {$copyId}.");
        if (!$copy->isCopy()) {
            throw new \InvalidArgumentException("Asset {$copyId} is not a copy, so there is nothing to make it from.");
        }
        $maker = null;
        if ($copy->maker !== self::MAKER_COPY) {
            $maker = $copy->maker !== null ? $this->makers?->get($copy->maker) : null;
            $maker ?? throw new \InvalidArgumentException("Copy {$copyId} was made by \"{$copy->maker}\", which no DerivedMaker is registered for.");
        }
        $originalId = (string) $copy->derivedFrom;
        $gone = fn (): \InvalidArgumentException => new \InvalidArgumentException($this->findBinned($originalId) !== null
            ? "The original {$originalId} of copy {$copyId} is in the bin; restore it first."
            : "The original {$originalId} of copy {$copyId} is gone.");
        $this->find($originalId) ?? throw $gone();
        $this->requireVersionsDir($copyId, $copy->originalName);

        // The original's record and the file the maker gets, read together: a replace running now cannot swap the file in between.
        [$original, $input] = $this->guarded($originalId, function () use ($originalId, $gone, $maker): array {
            $original = $this->find($originalId) ?? throw $gone();

            return [$original, $this->makerInput($original, raw: $maker === null)];
        });
        $made = null;
        try {
            $made = $maker?->make($original, $input, $params ?? $copy->makerParams) ?? $input;
            $bytes = $this->readFile($made, $original->originalName);
            $fields = ['derived_version' => $original->version] + ($params !== null ? ['maker_params' => $params] : []);

            return $this->locked($copyId, fn (): Asset => $this->swapFile($copyId, $bytes, $original->originalName, $by, [], $fields));
        } finally {
            @unlink($input);
            if ($made !== null && $made !== $input) {
                @unlink($made);
            }
        }
    }

    /** Whether remake() can run now: a copy whose original is there, made by "copy" or a registered maker, with versions_dir set. */
    public function canRemake(Asset $copy): bool
    {
        if (!$copy->isCopy() || $this->versionsDir() === null || $copy->maker === null) {
            return false;
        }
        if ($copy->maker !== self::MAKER_COPY && $this->makers?->has($copy->maker) !== true) {
            return false;
        }

        return $this->find((string) $copy->derivedFrom) !== null;
    }

    /** True when the copy's original changed since the copy was made: its file version is above the one the copy was made from. */
    public function isStale(Asset $copy): bool
    {
        if (!$copy->isCopy()) {
            return false;
        }
        $original = $this->find((string) $copy->derivedFrom);

        return $original !== null && $original->version > ($copy->derivedVersion ?? 0);
    }

    /**
     * What a maker gets of the original, as a file the caller deletes: the file as uploaded
     * ($raw, or an original that is not turned or mirrored, or no editable image), else the
     * original turned and mirrored without the crop, at full size (the editor's image, uncapped).
     *
     * @throws AssetUploadException
     */
    private function makerInput(Asset $original, bool $raw): string
    {
        $file = $this->file($original);
        if ($file === null || !is_file($file)) {
            throw new AssetUploadException("{$original->originalName}: the file of asset {$original->id} is missing.");
        }
        if ($raw || !$this->isEditable($original) || ($original->rotation === 0 && !$original->mirrored)) {
            $tmp = sys_get_temp_dir() . '/preflow-media-' . bin2hex(random_bytes(8)) . '.' . pathinfo($original->path, PATHINFO_EXTENSION);
            if (!@copy($file, $tmp)) {
                @unlink($tmp);
                throw new AssetUploadException("{$original->originalName}: the file of asset {$original->id} could not be copied for its copy.");
            }

            return $tmp;
        }
        // A name of its own per call (two remakes never share it) that removeDerived() knows, so a leftover goes with the original's next edit.
        $rel = self::stemOf($original->path) . '-oriented-' . Formats::key([$original->version, $original->rotation, $original->mirrored, 'full', bin2hex(random_bytes(8))]) . '.webp';
        if ($this->variants === null || !$this->withinMaxPixels($original) || !$this->renderOriented($original, $rel, 0)) {
            throw new AssetUploadException("{$original->originalName}: asset {$original->id} could not be turned and mirrored for its copy (no image backend, above max_pixels, or no WebP).");
        }

        return $this->absolute($rel);
    }

    /** @throws \InvalidArgumentException for an unknown asset, or a copy whose original is gone */
    private function originalOf(string $id): Asset
    {
        $asset = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        if (!$asset->isCopy()) {
            return $asset;
        }

        return $this->find((string) $asset->derivedFrom) ?? throw new \InvalidArgumentException("The original {$asset->derivedFrom} of asset {$id} is gone.");
    }

    /**
     * An upload's bytes and client file name, after the upload and size checks.
     *
     * @return array{0: string, 1: string}
     * @throws AssetUploadException
     */
    private function readUpload(UploadedFileInterface $file): array
    {
        $name = (string) ($file->getClientFilename() ?? 'upload');
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new AssetUploadException("{$name}: the upload failed (error {$file->getError()}).");
        }
        $this->checkSize($name, (int) ($file->getSize() ?? 0));
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return [$stream->getContents(), $name];
    }

    /**
     * A file on disk's bytes, after the existence and size checks.
     *
     * @throws AssetUploadException
     */
    private function readFile(string $path, string $name): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AssetUploadException("{$name}: the file does not exist.");
        }
        $this->checkSize($name, (int) filesize($path));

        return (string) file_get_contents($path);
    }

    /** A live asset: one in the bin is not found (pages, pickers and media_image() never resolve it; findBinned() does). */
    public function find(string $id): ?Asset
    {
        $asset = $this->record($id);

        return $asset !== null && $asset->binnedAt === null ? $asset : null;
    }

    /** An asset in the bin; null for a live or unknown one. */
    public function findBinned(string $id): ?Asset
    {
        $asset = $this->record($id);

        return $asset?->binnedAt !== null ? $asset : null;
    }

    /** The stored asset, live or binned. */
    private function record(string $id): ?Asset
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $record = $this->dm->findIn($this->definition(), $id);

        return $record === null ? null : Asset::fromRecord($record);
    }

    /** Only assets that are not in the bin (binned_at is empty, or missing on records from before the bin). */
    private function live(QueryBuilder $qb): QueryBuilder
    {
        return $qb->whereAny([['field' => 'binned_at', 'operator' => '=', 'value' => ''], ['field' => 'binned_at', 'operator' => '=', 'value' => null]]);
    }

    /** @return list<Asset> the live assets, newest first */
    public function all(): array
    {
        $assets = array_map(Asset::fromRecord(...), array_values($this->live($this->dm->queryIn($this->definition()))->get()->items()));
        usort($assets, static fn (Asset $a, Asset $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $assets;
    }

    /**
     * One page of live assets (none from the bin), newest first. $kind is image, video or document (null: all); $search
     * matches every word in the original name, title or alt text (in any locale). $filters
     * compare by equality on the extra fields declared in config "filters" (others are ignored):
     * a select or boolean field by value, a relation field by id (a list: any of them), a
     * multiple relation as "contains the id". The asset's own "private" flag filters the same
     * way as a boolean (a picker that lists only what its field accepts). A page past the end
     * gives the last page.
     *
     * Copies stand behind their original (a stack): $copies null hides them unless a search
     * looks for something (then a copy can be found and picked directly), true lists them,
     * false hides them. The condition is in the query, so totals and pages count what is shown.
     *
     * @param array<string, mixed> $filters field name => value
     */
    public function query(?string $kind = null, string $search = '', array $filters = [], int $page = 1, int $perPage = 50, ?bool $copies = null): AssetPage
    {
        $def = $this->definition();
        $qb = $this->live($this->dm->queryIn($def)->locale($this->locales->default));

        if ($kind !== null && $kind !== '') {
            $qb->whereAny([['field' => 'mime', 'operator' => 'IN', 'value' => array_keys(array_filter(
                self::EXTENSIONS,
                static fn (string $ext, string $mime) => Asset::kindOf($mime) === $kind,
                ARRAY_FILTER_USE_BOTH,
            ))]]);
        }
        $this->applySearch($qb, $search);
        foreach ((array) ($this->config['filters'] ?? []) as $name) {
            if (is_string($name) && isset($def->fields[$name]) && array_key_exists($name, $filters)) {
                $this->applyFilter($qb, $def->fields[$name], $filters[$name]);
            }
        }
        // Stored as 1/0 (missing on older records: public); IN compares as text in every driver.
        $private = match ($filters['private'] ?? null) {
            true, 1, '1' => true,
            false, 0, '0' => false,
            default => null,
        };
        if ($private === true) {
            $qb->whereAny([['field' => 'private', 'operator' => 'IN', 'value' => ['1']]]);
        } elseif ($private === false) {
            $qb->whereAny([['field' => 'private', 'operator' => 'IN', 'value' => ['0']], ['field' => 'private', 'operator' => '=', 'value' => null]]);
        }
        if (!($copies ?? trim($search) !== '')) {
            // Empty for an original; missing (NULL) on records from before copies existed.
            $qb->whereAny([['field' => 'derived_from', 'operator' => '=', 'value' => ''], ['field' => 'derived_from', 'operator' => '=', 'value' => null]]);
        }
        $qb->orderBy('created_at', SortDirection::Desc)->orderBy($def->idField, SortDirection::Desc);

        return self::page($qb, $page, $perPage);
    }

    /** One page of the assets in the bin, the most recently binned first. A page past the end gives the last page. */
    public function binned(int $page = 1, int $perPage = 50): AssetPage
    {
        $def = $this->definition();
        $qb = $this->dm->queryIn($def)->where('binned_at', '>', '')
            ->orderBy('binned_at', SortDirection::Desc)->orderBy($def->idField, SortDirection::Desc);

        return self::page($qb, $page, $perPage);
    }

    /** A page of the query's assets; a page past the end gives the last page. */
    private static function page(QueryBuilder $qb, int $page, int $perPage): AssetPage
    {
        $perPage = max(1, $perPage);
        $result = $qb->paginate($perPage, max(1, $page));
        if ($result->currentPage > $result->lastPage) {
            $result = $qb->paginate($perPage, $result->lastPage);
        }

        return new AssetPage(
            array_map(Asset::fromRecord(...), array_values($result->items)),
            $result->total,
            $result->currentPage,
            $perPage,
        );
    }

    /**
     * Every word must occur in the original name, the title or the alt text of any locale. The
     * query reads translatable fields in one locale, so the other locales' alt texts are matched
     * by a pre-query per locale and word, whose ids join that word's alternatives.
     */
    private function applySearch(QueryBuilder $qb, string $search): void
    {
        $words = preg_split('/\s+/u', trim(mb_substr(trim($search), 0, 200)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_slice(array_values(array_unique($words)), 0, QueryBuilder::MAX_LOOSE_WORDS);
        $def = $this->definition();
        foreach ($words as $word) {
            $any = [];
            foreach (['original_name', 'title', 'alt'] as $field) {
                $any[] = ['field' => $field, 'operator' => 'CONTAINS', 'value' => $word];
            }
            foreach ($this->locales->available as $locale) {
                if ($locale === $this->locales->default) {
                    continue;
                }
                $ids = [];
                $matches = $this->dm->queryIn($def)->locale($locale)->whereAny([['field' => 'alt', 'operator' => 'CONTAINS', 'value' => $word]])->get();
                foreach ($matches->items() as $record) {
                    $ids[] = (string) $record->getId();
                }
                if ($ids !== []) {
                    $any[] = ['field' => $def->idField, 'operator' => 'IN', 'value' => $ids];
                }
            }
            $qb->whereAny($any);
        }
    }

    /** One declared filter on the query; a value that cannot be used (empty, wrong shape) adds nothing. */
    private function applyFilter(QueryBuilder $qb, TypeFieldDefinition $field, mixed $value): void
    {
        if ($field->type === 'boolean') {
            $bool = match (true) {
                $value === true || $value === 1 || $value === '1' => true,
                $value === false || $value === 0 || $value === '0' => false,
                default => null,
            };
            if ($bool === true) {
                $qb->where($field->name, '=', true);
            } elseif ($bool === false) {
                // Not set includes assets from before the field existed (no value stored).
                $qb->whereAny([['field' => $field->name, 'operator' => '=', 'value' => false], ['field' => $field->name, 'operator' => '=', 'value' => null]]);
            }

            return;
        }
        $values = array_values(array_filter(
            array_map(static fn ($v) => is_string($v) || is_int($v) ? (string) $v : '', is_array($value) ? $value : [$value]),
            static fn (string $v) => $v !== '' && strlen($v) <= 255,
        ));
        if ($values === []) {
            return;
        }
        if ($field->type === 'relation') {
            $multiple = ($field->config['relation']['multiple'] ?? false) === true;
            $qb->whereAny([['field' => $field->name, 'operator' => $multiple ? 'HAS' : 'IN', 'value' => $values]]);
        } elseif (count($values) === 1) {
            $qb->where($field->name, '=', $values[0]);
        } else {
            $qb->whereAny([['field' => $field->name, 'operator' => 'IN', 'value' => $values]]);
        }
    }

    /**
     * Edit metadata: alt (merged per locale), title, focal_x/focal_y (0–100),
     * poster (an image asset id; anything else clears it) and the configured extra
     * fields (a value that does not fit its field is cleared; other keys are ignored).
     *
     * @param array<string, mixed> $meta
     */
    public function update(string $id, array $meta): Asset
    {
        $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");

        // Read and saved under the asset's lock: a replace running now is never undone.
        return $this->guarded($id, fn (): Asset => $this->applyMeta($id, $meta));
    }

    /** @param array<string, mixed> $meta */
    private function applyMeta(string $id, array $meta): Asset
    {
        $asset = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        $data = $asset->toData();

        if (is_array($meta['alt'] ?? null)) {
            $alt = $asset->alt;
            foreach ($meta['alt'] as $locale => $text) {
                if (is_string($locale) && $this->locales->has($locale) && is_string($text)) {
                    $alt[$locale] = trim($text);
                }
            }
            $data['alt'] = $alt;
        }
        if (array_key_exists('title', $meta)) {
            $data['title'] = is_string($meta['title']) ? trim($meta['title']) : '';
        }
        foreach (['focal_x', 'focal_y'] as $key) {
            if (array_key_exists($key, $meta) && is_numeric($meta[$key])) {
                $data[$key] = max(0, min(100, (int) round((float) $meta[$key])));
            }
        }
        if (array_key_exists('poster', $meta)) {
            $poster = is_string($meta['poster']) && $meta['poster'] !== $id ? $this->find($meta['poster']) : null;
            $data['poster'] = $poster !== null && $poster->kind() === 'image' ? $poster->id : '';
        }
        foreach ($this->extraFields() as $name => $field) {
            if (array_key_exists($name, $meta)) {
                $data[$name] = self::extraValue($field, $meta[$name]);
            }
        }

        $saved = $this->save($data);
        if ([$saved->focalX, $saved->focalY] !== [$asset->focalX, $asset->focalY]) {
            $this->removeBoxes($saved); // their cut follows the focal point
        }

        return $saved;
    }

    /** True for a public JPEG, PNG, WebP or AVIF image: only those can be cropped, turned and mirrored. */
    public function isEditable(Asset $asset): bool
    {
        return !$asset->private && in_array($asset->mime, self::EDITABLE, true);
    }

    /**
     * Crop the image (percent of the turned and mirrored original); null removes the crop.
     * The focal point keeps pointing at the same spot of the picture.
     *
     * @throws \InvalidArgumentException for an unknown asset or one that cannot be edited
     */
    public function setCrop(string $id, ?CropRect $rect): Asset
    {
        $rect = $rect !== null && !$rect->isWhole() ? $rect : null;

        return $this->edit($id, static function (Asset $asset) use ($rect): array {
            [$x, $y] = Geometry::focalAfter($asset->focalX, $asset->focalY, $asset->crop, $rect);

            return ['crop' => $rect?->toArray() ?? [], 'focal_x' => $x, 'focal_y' => $y];
        });
    }

    /**
     * Turn the image clockwise by quarter turns (negative: counter-clockwise). The crop and
     * the focal point turn with it; the per-format crops are cleared (their box ratio no
     * longer fits).
     *
     * @throws \InvalidArgumentException for an unknown asset or one that cannot be edited
     */
    public function rotate(string $id, int $quarterTurns): Asset
    {
        $turns = (($quarterTurns % 4) + 4) % 4;
        if ($turns === 0) {
            return $this->editable($id); // whole turns: the image, its crops and files stay as they are
        }

        return $this->edit($id, static function (Asset $asset) use ($turns): array {
            [$x, $y] = Geometry::pointRotated($asset->focalX, $asset->focalY, $turns);

            return [
                'rotation' => ($asset->rotation + $turns) % 4,
                'crop' => $asset->crop?->rotated($turns)->toArray() ?? [],
                'crops' => [],
                'focal_x' => $x,
                'focal_y' => $y,
            ];
        });
    }

    /**
     * Mirror the image as it is shown, left-right ($horizontal) or top-bottom. The crop,
     * the per-format crops and the focal point are mirrored with it.
     *
     * @throws \InvalidArgumentException for an unknown asset or one that cannot be edited
     */
    public function flip(string $id, bool $horizontal): Asset
    {
        return $this->edit($id, static function (Asset $asset) use ($horizontal): array {
            [$x, $y] = Geometry::pointMirrored($asset->focalX, $asset->focalY, $horizontal);

            // The file is mirrored left-right before it turns: mirroring what is shown turns the other way.
            return [
                'rotation' => (($horizontal ? 4 : 6) - $asset->rotation) % 4,
                'flip' => $asset->mirrored ? '' : 'h',
                'crop' => $asset->crop?->mirrored($horizontal)->toArray() ?? [],
                'crops' => array_map(static fn (CropRect $c) => $c->mirrored($horizontal)->toArray(), $asset->crops),
                'focal_x' => $x,
                'focal_y' => $y,
            ];
        });
    }

    /**
     * Set the crop a cover box preset takes (percent of the turned and mirrored original,
     * cut to the box ratio around its centre); null goes back to the focal point. The
     * master stays; the box files are made again on their next request.
     *
     * @throws \InvalidArgumentException for an unknown or uneditable asset, or a preset that is not a cover box
     */
    public function setFormatCrop(string $id, string $preset, ?CropRect $rect): Asset
    {
        $this->editable($id);
        if ($this->formats?->get($preset)?->fit !== 'cover') {
            throw new \InvalidArgumentException("Preset \"{$preset}\" is not a cover box in config/image.php, so it takes no crop.");
        }

        return $this->guarded($id, function () use ($id, $preset, $rect): Asset {
            $data = $this->editable($id)->toData();
            if ($rect === null) {
                unset($data['crops'][$preset]);
            } else {
                $data['crops'][$preset] = $rect->toArray();
            }
            $saved = $this->save($data);
            $this->removeBoxes($saved);

            return $saved;
        });
    }

    /**
     * The region a cover preset shows now, in percent of the turned and mirrored original:
     * its format crop fitted to the box ratio, else the focal box inside the image crop.
     * A contain preset shows the whole image crop. Null for any other preset.
     */
    public function formatRegion(Asset $asset, string $preset): ?CropRect
    {
        $format = $this->formats?->get($preset);
        if ($format === null || $format->ratio() === null || $asset->kind() !== 'image' || $asset->width === null || $asset->height === null) {
            return null;
        }
        [$w, $h] = self::orientedSize($asset);

        return CropRect::fromPixels($this->region($asset, $format, $w, $h), $w, $h);
    }

    /**
     * What a box preset takes, in pixels of the turned and mirrored original ($w x $h): for
     * cover, its format crop (the part inside the image crop) fitted to the box ratio around
     * its centre, else the focal box inside the image crop; for contain, the image crop.
     *
     * @return array{x: int, y: int, w: int, h: int}
     */
    private function region(Asset $asset, Format $format, int $w, int $h): array
    {
        $crop = $asset->crop?->pixels($w, $h) ?? ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h];
        $ratio = (float) $format->ratio();
        if ($format->fit !== 'cover') {
            return $crop;
        }
        $own = $asset->crops[$format->name] ?? null;
        if ($own !== null) {
            $o = $own->pixels($w, $h);
            $x = max($o['x'], $crop['x']);
            $y = max($o['y'], $crop['y']);
            $right = min($o['x'] + $o['w'], $crop['x'] + $crop['w']);
            $bottom = min($o['y'] + $o['h'], $crop['y'] + $crop['h']);
            if ($right - $x >= 1 && $bottom - $y >= 1) {
                return Geometry::fitRatio(['x' => $x, 'y' => $y, 'w' => $right - $x, 'h' => $bottom - $y], $ratio);
            }
        }
        // The focal point is in percent of the delivery image, which is the image crop.
        $box = Geometry::focalBox($crop['w'], $crop['h'], $ratio, $asset->focalX, $asset->focalY);

        return ['x' => $crop['x'] + $box['x'], 'y' => $crop['y'] + $box['y'], 'w' => $box['w'], 'h' => $box['h']];
    }

    /** @return array{int, int} the original's size once turned */
    private static function orientedSize(Asset $asset): array
    {
        return $asset->rotation % 2 === 1 ? [(int) $asset->height, (int) $asset->width] : [(int) $asset->width, (int) $asset->height];
    }

    /**
     * How a box preset (cover, contain) is cut from the asset's delivery image now: the
     * region (formatRegion() in pixels of the delivery image), the display size, the file
     * widths and the base of the file names ({delivery stem}-{preset}-{key}, the key from
     * the delivery stem, the preset and the region). Null for a preset that is not a box,
     * a private asset, no delivery image, no variants backend, or above max_pixels.
     */
    public function boxPlan(Asset $asset, string $preset): ?BoxPlan
    {
        $format = $this->formats?->get($preset);
        $ratio = $format?->ratio();
        if ($format === null || $ratio === null || $asset->private || $this->variants === null) {
            return null;
        }
        $source = $this->deliveryAsset($asset);
        if ($source === null || $source->width === null || $source->height === null || $source->width < 1 || $source->height < 1 || !$this->withinMaxPixels($source)) {
            return null;
        }
        [$sw, $sh] = [$source->width, $source->height];
        if ($asset->kind() === 'image' && ($source->path !== $asset->path || !$asset->isEdited()) && $asset->width !== null && $asset->height !== null) {
            // In pixels of the turned original, moved into the delivery image (the image crop, scaled to it).
            [$w, $h] = self::orientedSize($asset);
            $region = $this->region($asset, $format, $w, $h);
            $cut = $asset->crop?->pixels($w, $h) ?? ['x' => 0, 'y' => 0, 'w' => $w, 'h' => $h];
            [$fx, $fy] = [$sw / $cut['w'], $sh / $cut['h']];
            $x = max(0, min($sw - 1, (int) round(($region['x'] - $cut['x']) * $fx)));
            $y = max(0, min($sh - 1, (int) round(($region['y'] - $cut['y']) * $fy)));
            $crop = ['x' => $x, 'y' => $y, 'w' => max(1, min($sw - $x, (int) round($region['w'] * $fx))), 'h' => max(1, min($sh - $y, (int) round($region['h'] * $fy)))];
        } else {
            // A document's preview, or an edit whose master could not be made: the picture shown, around its focal point.
            $crop = $format->fit === 'cover'
                ? Geometry::focalBox($sw, $sh, $ratio, $asset->focalX, $asset->focalY)
                : ['x' => 0, 'y' => 0, 'w' => $sw, 'h' => $sh];
        }
        [$width, $height] = $format->fit === 'cover'
            ? [$format->width, (int) $format->height]
            : Geometry::containSize($crop['w'], $crop['h'], $format->width, (int) $format->height);
        $wanted = $format->widths !== [] ? $format->widths : [$width, 2 * $width];
        $widths = array_values(array_filter($wanted, static fn (int $w): bool => $w <= $crop['w']));
        if ($crop['w'] < max($wanted)) {
            $widths[] = $crop['w'];
        }
        $widths = array_values(array_unique($widths));
        sort($widths);
        $stem = self::stemOf($source->path);
        $key = Formats::key([$stem, $format->name, $format->width, $format->height, $format->fit, $format->widths, $crop['x'], $crop['y'], $crop['w'], $crop['h']]);

        return new BoxPlan($format, $source, "{$stem}-{$format->name}-{$key}", $width, $height, $widths, $crop);
    }

    /** Whether a box file may exist: a width of the plan, in a format the backend can write for the source. */
    public function allowsBox(BoxPlan $plan, int $width, string $format): bool
    {
        return $this->variants !== null && in_array($width, $plan->widths, true) && $this->variants->supports($plan->source->mime, $format);
    }

    /**
     * The file of one box variant ({base}-{width}.{format}), made now if missing; concurrent
     * first requests wait on a per-file lock, so it is encoded once. Null for a width or
     * format the plan does not allow, or when it could not be made.
     */
    public function boxFile(BoxPlan $plan, int $width, string $format): ?string
    {
        if (!$this->allowsBox($plan, $width, $format)) {
            return null;
        }
        $rel = "{$plan->base}-{$width}.{$format}";
        // The plan was computed without the asset lock: a request racing an edit can write a
        // file under the old key after removeBoxes() ran. Nothing links it; like a plain
        // variant made on demand it is an orphan, removed at the next edit or delete.

        return $this->madeOnce($rel, fn () => $this->variants?->generateBox($this->dir, $plan->source->path, $plan->source->mime, $plan->crop, $width, $format, $rel));
    }

    /**
     * The editor's image: the original turned and mirrored, without the crop, at most
     * ORIENTED_LONG_SIDE on its long side ({stem}-oriented-{key}.webp, made on the first
     * call). Null when it cannot be made (not editable, no WebP, needs Imagick).
     */
    public function orientedUrl(Asset $asset): ?string
    {
        if (!$this->isEditable($asset) || $this->variants === null || !$this->withinMaxPixels($asset)) {
            return null;
        }
        $rel = self::stemOf($asset->path) . '-oriented-' . Formats::key([$asset->version, $asset->rotation, $asset->mirrored, 'oriented']) . '.webp';
        if (!is_file($this->absolute($rel)) && !$this->renderOriented($asset, $rel, self::ORIENTED_LONG_SIDE)) {
            return null;
        }

        return $this->url($rel);
    }

    /**
     * Write the original turned and mirrored, without the crop, to $rel (WebP, beside the file),
     * at most $longSide on its long side (0: full size). False when it could not be made.
     */
    private function renderOriented(Asset $asset, string $rel, int $longSide): bool
    {
        try {
            return $this->variants?->renderEdited($this->dir, $asset->path, $asset->mime, $asset->rotation, $asset->mirrored, null, $rel, $longSide) !== null;
        } catch (\Throwable $e) {
            error_log("preflow/media: no turned image of asset {$asset->id}: " . $e::class . ': ' . $e->getMessage());

            return false;
        }
    }

    /**
     * One edit under the asset's lock: the old files made from the edits go (master and
     * its variants, the file's variants, box files, the editor's image), the new edit state
     * is saved, then the master, colours and variants are made again. Undoing every edit
     * so brings back the file's variants under their usual names.
     *
     * @param \Closure(Asset): array<string, mixed> $change the changed fields of the record
     */
    private function edit(string $id, \Closure $change): Asset
    {
        $this->editable($id);

        return $this->guarded($id, fn (): Asset => $this->remakeDerived($this->editable($id), $change));
    }

    /**
     * With the lock held: remove what was made from the file and its edits, save (with
     * $change applied), then make the master and variants again (and the colours).
     *
     * @param ?\Closure(Asset): array<string, mixed> $change
     */
    private function remakeDerived(Asset $asset, ?\Closure $change = null, bool $colors = true): Asset
    {
        $data = ($change !== null ? $change($asset) : []) + $asset->toData();
        $this->removeDerived($asset);
        $data['master'] = [];
        $data['variants'] = [];
        if ($asset->note === self::NOTE_NEEDS_IMAGICK) {
            $data['note'] = ''; // set again if it still applies
        }

        return $this->deliver($this->save($data), $colors);
    }

    /** @throws \InvalidArgumentException for an unknown asset or one that cannot be edited */
    private function editable(string $id): Asset
    {
        $asset = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        if (!$this->isEditable($asset)) {
            throw new \InvalidArgumentException("Asset {$id} cannot be edited: only public JPEG, PNG, WebP and AVIF images can.");
        }

        return $asset;
    }

    /**
     * Move the asset into the bin (with bin_dir; restoreFromBin() brings it back), or remove it
     * for good without one. A usage that does not block a delete (an earlier revision) lets it bin.
     *
     * @throws AssetInUseException while a usage source reports a usage that blocks a delete (nothing changes)
     * @throws \RuntimeException when a file could not be moved into the bin (nothing changes)
     */
    public function delete(string $id): void
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return;
        }
        $usages = $this->usage?->blocking($id) ?? [];
        if ($usages !== []) {
            throw new AssetInUseException($id, $usages);
        }
        if ($this->binDir() !== null) {
            $this->bin($asset);
        } else {
            $this->remove($asset);
        }
    }

    /**
     * Bring an asset back from the bin: its files go back where they were (the media dir, the
     * private dir for a private asset, its earlier files to versions_dir), the record is live
     * again, and what is made from the file (variants, an edited image's master) is made again;
     * its colours stay. A copy whose original is in the bin brings the original back first; a
     * copy whose original was deleted for good becomes an ordinary asset (its link to the
     * original, maker and maker settings are cleared).
     *
     * @throws \InvalidArgumentException for an asset that is not in the bin
     * @throws \RuntimeException without bin_dir, or when a file could not be moved back (nothing changes)
     */
    public function restoreFromBin(string $id): Asset
    {
        $asset = $this->findBinned($id) ?? throw new \InvalidArgumentException("Asset {$id} is not in the bin.");
        $this->requireBinDir();
        if ($asset->derivedFrom !== null && $this->findBinned($asset->derivedFrom) !== null) {
            $this->restoreFromBin($asset->derivedFrom);
        }

        return $this->guarded($id, function () use ($id): Asset {
            $asset = $this->findBinned($id) ?? throw new \InvalidArgumentException("Asset {$id} is not in the bin."); // restored while we waited
            $moves = $this->restoreMoves($asset);
            self::moveAll($moves, "Asset {$id} could not be restored");
            $data = $asset->toData();
            $data['binned_at'] = '';
            if ($asset->derivedFrom !== null && $this->record($asset->derivedFrom) === null) {
                $data = ['derived_from' => '', 'maker' => '', 'maker_params' => [], 'derived_version' => null] + $data;
            }
            try {
                $saved = $this->save($data);
            } catch (\Throwable $e) {
                self::moveAll(self::reversed($moves), "Asset {$id} could not be put back into the bin");
                throw $e;
            }
            $this->removeTree($this->binDir() . '/' . $id);

            return $this->remakeDerived($saved, colors: false);
        });
    }

    /**
     * Delete for good what has been in the bin longer than $olderThanDays, the longest binned
     * first, except an asset a usage source still reports, blocking or not (an earlier revision,
     * a live copy of an original, the poster of a binned video): it stays in the bin and is
     * listed with the reason. $progress is called for each asset checked, with whether it was
     * deleted. In chunks: $offset skips that many old enough assets, $limit checks at most that
     * many; deleted assets leave the bin, so the next chunk starts after the kept ones
     * ($offset + count of kept), and the result says how many are left.
     *
     * @param ?\Closure(Asset, bool): void $progress
     * @throws \RuntimeException without bin_dir
     */
    public function emptyBin(int $olderThanDays, ?\Closure $progress = null, ?int $limit = null, int $offset = 0): BinEmptyResult
    {
        $this->requireBinDir();
        $cutoff = ($this->now)()->modify('-' . max(0, $olderThanDays) . ' days');
        $old = [];
        foreach ($this->dm->queryIn($this->definition())->where('binned_at', '>', '')->get()->items() as $record) {
            $asset = Asset::fromRecord($record);
            $at = \DateTimeImmutable::createFromFormat(DATE_ATOM, (string) $asset->binnedAt) ?: null;
            if ($at !== null && $at < $cutoff) {
                $old[] = [$at, $asset->id, $asset]; // an unreadable date is kept
            }
        }
        // Longest binned first, then by id: the same order on every run.
        usort($old, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $old = array_column($old, 2);
        $chunk = array_slice($old, max(0, $offset), $limit !== null ? max(0, $limit) : null);
        $deleted = 0;
        $kept = [];
        foreach ($chunk as $asset) {
            $usages = $this->usage?->of($asset->id) ?? [];
            if ($usages !== []) {
                $kept[] = ['id' => $asset->id, 'reason' => self::stillUsed($usages)];
                if ($progress !== null) {
                    $progress($asset, false);
                }
                continue;
            }
            $gone = $this->purge($asset->id);
            $deleted += $gone ? 1 : 0;
            if ($progress !== null) {
                $progress($asset, $gone);
            }
        }

        return new BinEmptyResult($deleted, $kept, max(0, count($old) - max(0, $offset) - count($chunk)));
    }

    /** @param non-empty-list<AssetUsageEntry> $usages */
    private static function stillUsed(array $usages): string
    {
        return 'still used: ' . implode('; ', array_map(
            static fn (AssetUsageEntry $u): string => "{$u->label} ({$u->source}" . ($u->note !== null && $u->note !== '' ? ", {$u->note}" : '') . ')',
            $usages,
        ));
    }

    /** The bin directory (config "bin_dir"); null when none is configured, and then a delete removes an asset for good. */
    public function binDir(): ?string
    {
        $dir = $this->config['bin_dir'] ?? null;

        return is_string($dir) && $dir !== '' ? rtrim($dir, '/') : null;
    }

    /** @throws \RuntimeException without a bin directory */
    private function requireBinDir(): void
    {
        if ($this->binDir() === null) {
            throw new \RuntimeException('No bin directory is configured (bin_dir in config/media.php).');
        }
    }

    /**
     * With bin_dir, under the asset's lock: move its own files (the current file, a document's
     * preview, its earlier files) into {bin_dir}/{id}/ (files/, versions/), mark the record
     * binned, then delete what was made from them (variants, the rendered master, box files,
     * the editor's image); restoreFromBin() makes those again.
     */
    private function bin(Asset $asset): void
    {
        $this->guarded($asset->id, function () use ($asset): void {
            $current = $this->find($asset->id); // a replace may have run while we waited
            if ($current === null) {
                return; // binned or deleted meanwhile
            }
            $bin = $this->binDir() . '/' . $current->id;
            $moves = [];
            foreach ([$this->file($current), $this->previewFile($current)] as $file) {
                if ($file !== null && is_file($file)) {
                    $moves[] = [$file, $bin . '/files/' . basename($file)];
                }
            }
            $versions = $this->versionsDir() !== null ? $this->versionsDir() . '/' . $current->id : null;
            foreach ($versions !== null ? self::filesIn($versions) : [] as $name) {
                $moves[] = [$versions . '/' . $name, $bin . '/versions/' . $name];
            }
            self::moveAll($moves, "Asset {$current->id} could not be moved into the bin");
            $data = $current->toData();
            // In UTC: the same offset everywhere, so text order (the bin's sorting) is time order.
            $data['binned_at'] = ($this->now)()->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
            $data['variants'] = [];
            $data['master'] = [];
            try {
                $this->save($data);
            } catch (\Throwable $e) {
                self::moveAll(self::reversed($moves), "Asset {$current->id} could not be taken back out of the bin");
                throw $e;
            }
            if ($versions !== null) {
                @rmdir($versions);
            }
            // The record is binned: what was made from the files goes (the moved files are no longer there).
            $this->removeFiles($current);
            $this->removeLeftovers($current);
        });
    }

    /**
     * Where each of a binned asset's files in the bin goes back to.
     *
     * @return list<array{0: string, 1: string}> from, to
     * @throws \RuntimeException when a file has nowhere to go (no private_dir, no versions_dir)
     */
    private function restoreMoves(Asset $asset): array
    {
        $bin = $this->binDir() . '/' . $asset->id;
        $moves = [];
        if ($this->file($asset) === null) {
            throw new \RuntimeException("Asset {$asset->id} could not be restored: no private directory is configured (private_dir).");
        }
        foreach (array_filter([$this->file($asset), $this->previewFile($asset)]) as $target) {
            if (is_file($bin . '/files/' . basename($target))) {
                $moves[] = [$bin . '/files/' . basename($target), $target];
            }
        }
        $versions = self::filesIn($bin . '/versions');
        if ($versions !== [] && $this->versionsDir() === null) {
            throw new \RuntimeException("Asset {$asset->id} could not be restored: no versions directory is configured (versions_dir) for its earlier files.");
        }
        foreach ($versions as $name) {
            $moves[] = [$bin . '/versions/' . $name, $this->versionsDir() . '/' . $asset->id . '/' . $name];
        }

        return $moves;
    }

    /** Delete a binned asset for good: its record, then its files in the bin. False when it is no longer in the bin. */
    private function purge(string $id): bool
    {
        return $this->guarded($id, function () use ($id): bool {
            if ($this->findBinned($id) === null) {
                return false; // restored meanwhile
            }
            $this->dm->deleteIn($this->definition(), $id);
            $this->removeTree($this->binDir() . '/' . $id);
            $this->removeVersionsFolder($id);
            if ($this->versionsDir() !== null) {
                @unlink($this->versionsDir() . '/' . self::LOCKS . '/' . $id . '.lock');
            }

            return true;
        });
    }

    /**
     * Move each file (rename, so a file is never half there); when one fails, the ones moved go back.
     *
     * @param list<array{0: string, 1: string}> $moves from, to
     * @throws \RuntimeException when a file could not be moved (nothing changed)
     */
    private static function moveAll(array $moves, string $failure): void
    {
        $done = [];
        foreach ($moves as [$from, $to]) {
            $dir = dirname($to);
            if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) || !@rename($from, $to)) {
                foreach (array_reverse($done) as [$movedFrom, $movedTo]) {
                    @rename($movedTo, $movedFrom);
                }
                throw new \RuntimeException("{$failure}: {$from} could not be moved to {$to}; nothing was changed.");
            }
            $done[] = [$from, $to];
        }
    }

    /**
     * @param list<array{0: string, 1: string}> $moves
     * @return list<array{0: string, 1: string}> the same moves the other way, last first
     */
    private static function reversed(array $moves): array
    {
        return array_map(static fn (array $m): array => [$m[1], $m[0]], array_reverse($moves));
    }

    /** @return list<string> the names of the files directly in $dir ([] when it does not exist) */
    private static function filesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(scandir($dir) ?: [], static fn (string $e): bool => $e !== '.' && $e !== '..' && is_file($dir . '/' . $e)));
    }

    /** Delete a directory with everything in it. */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Delete for good without asking the usage sources (never into the bin). Only for an asset
     * created in this request that nothing can reference yet (an upload a field then refuses).
     */
    public function discard(string $id): void
    {
        $asset = $this->find($id);
        if ($asset !== null) {
            $this->remove($asset);
        }
    }

    /**
     * Swap the asset's file and keep the old one as a version. Everything else stays
     * (alt, title, focal point, poster, extra fields, private flag); what derives from
     * the file is made again. The new file must be the same kind (image, video,
     * document) and passes the upload checks. A string is the absolute path of a file
     * on disk (importers), which stays where it is.
     *
     * The order keeps the record on a complete file at every step: write the new file,
     * copy the old one into the versions dir, save the record, then delete the old file
     * and its variants and make the new derived files. A per-asset lock serialises
     * replaces, restores, version deletes and prunes of one asset.
     *
     * @throws AssetUploadException (nothing changed)
     * @throws \InvalidArgumentException for an unknown asset
     */
    public function replace(string $id, UploadedFileInterface|string $file, ?string $by): Asset
    {
        [$bytes, $name] = is_string($file) ? [$this->readFile($file, basename($file)), basename($file)] : $this->readUpload($file);
        $this->requireVersionsDir($id, $name);

        return $this->locked($id, fn (): Asset => $this->swapFile($id, $bytes, $name, $by, []));
    }

    /** @return list<AssetVersion> the asset's earlier files, newest first ([] for an unknown asset) */
    public function versions(string $id): array
    {
        $versions = $this->find($id)?->versions ?? [];
        usort($versions, static fn (AssetVersion $a, AssetVersion $b) => $b->number <=> $a->number);

        return $versions;
    }

    /**
     * Make an earlier file current again: a replace from that version's file, so the
     * current file becomes a version too. The version's snapshot (focal point) comes
     * back; alt texts and title stay as they are now. The restored version is kept.
     *
     * @throws AssetUploadException when the version's file is gone (nothing changed)
     */
    public function restore(string $id, int $version, ?string $by): Asset
    {
        $this->requireVersionsDir($id, "Version {$version}");

        return $this->locked($id, function () use ($id, $version, $by): Asset {
            $asset = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
            $old = self::versionOf($asset, $version) ?? throw new \InvalidArgumentException("Asset {$id} has no version {$version}.");
            $file = $this->versionFile($id, $version);
            if ($file === null) {
                throw new AssetUploadException("Version {$version} of {$asset->label()}: the file is missing.");
            }
            $name = is_string($old->meta['original_name'] ?? null) && $old->meta['original_name'] !== '' ? $old->meta['original_name'] : basename($file);

            return $this->swapFile($id, (string) file_get_contents($file), $name, $by, $old->meta);
        });
    }

    /** Delete one earlier file (an unknown asset or version: nothing happens). Its number is never used again. */
    public function deleteVersion(string $id, int $version): void
    {
        $asset = $this->find($id);
        if ($asset === null || self::versionOf($asset, $version) === null || $this->versionsDir() === null) {
            return;
        }
        $this->locked($id, fn (): int => $this->dropVersions($id, static fn (Asset $a): array => [$version]));
    }

    /** The absolute path of an earlier file; null for an unknown asset, version or a missing file. */
    public function versionFile(string $id, int $version): ?string
    {
        $asset = $this->find($id);
        $dir = $this->versionsDir();
        $entry = $asset !== null ? self::versionOf($asset, $version) : null;
        // Only the name a replace writes: a record cannot point anywhere else.
        if ($entry === null || $dir === null || preg_match('#^' . $id . '/v' . $version . '\.[a-z0-9]+$#', $entry->path) !== 1) {
            return null;
        }
        $file = $dir . '/' . $entry->path;

        return is_file($file) ? $file : null;
    }

    /**
     * Delete earlier files beyond the $keep newest; with $olderThanDays only those
     * replaced more than that many days ago. The current file is never touched.
     * Returns how many were deleted.
     */
    public function prune(string $id, int $keep, ?int $olderThanDays = null): int
    {
        $doomed = function (Asset $asset) use ($keep, $olderThanDays): array {
            $versions = $asset->versions;
            usort($versions, static fn (AssetVersion $a, AssetVersion $b) => $b->number <=> $a->number);
            $cutoff = $olderThanDays !== null ? ($this->now)()->modify('-' . max(0, $olderThanDays) . ' days') : null;
            $numbers = [];
            foreach (array_slice($versions, max(0, $keep)) as $v) {
                $at = $cutoff !== null ? (\DateTimeImmutable::createFromFormat(DATE_ATOM, $v->createdAt) ?: null) : null;
                if ($cutoff === null || ($at !== null && $at < $cutoff)) { // an unreadable date is kept
                    $numbers[] = $v->number;
                }
            }

            return $numbers;
        };
        $asset = $this->find($id);
        // Checked before locking, so pruning a library does not make a lock per asset.
        if ($asset === null || $doomed($asset) === [] || $this->versionsDir() === null) {
            return 0;
        }

        return $this->locked($id, fn (): int => $this->dropVersions($id, $doomed));
    }

    /**
     * With the lock held: write the new file, keep the old one as a version, save,
     * then remove the old file's leftovers and make the new derived files.
     *
     * @param array<string, mixed> $restore a version's meta snapshot to bring back ([] for a plain replace)
     * @param array<string, mixed> $fields record fields set with the new file (a remade copy's settings and original version)
     * @throws AssetUploadException
     */
    private function swapFile(string $id, string $bytes, string $name, ?string $by, array $restore, array $fields = []): Asset
    {
        $asset = $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        [$mime, $ext] = $this->sniff($bytes, $name);
        if (Asset::kindOf($mime) !== $asset->kind()) {
            throw new AssetUploadException("{$name}: a {$asset->kind()} can only be replaced by another {$asset->kind()}.");
        }
        $base = $asset->private ? $this->privateDir() : rtrim($this->dir, '/');
        if ($base === null) {
            throw new AssetUploadException("{$name}: no private directory is configured (assets.private_dir).");
        }

        // Numbers only grow: above the current one and every kept (or deleted) version.
        $number = max([$asset->version, ...array_map(static fn (AssetVersion $v) => $v->number, $asset->versions)]) + 1;
        $yearMonth = dirname($asset->path);
        $path = $this->versionedPath($id, $ext, $number, preg_match('#^\d{4}/\d{2}$#', $yearMonth) === 1 ? $yearMonth : ($this->now)()->format('Y/m'));
        $target = $base . '/' . $path;
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) {
            throw new AssetUploadException('The media directory is not writable.');
        }
        self::writeAtomically($target, $bytes) || throw new AssetUploadException("{$name}: the file could not be written to the media directory.");

        // The old file is copied, not moved: until the record is saved it still points there.
        $versions = $asset->versions;
        $kept = null;
        $old = $this->file($asset);
        if ($old !== null && is_file($old)) {
            $rel = $id . '/v' . $asset->version . '.' . pathinfo($asset->path, PATHINFO_EXTENSION);
            $kept = $this->versionsDir() . '/' . $rel;
            if (!self::writeAtomically($kept, null, $old)) {
                @unlink($target);
                throw new AssetUploadException("{$name}: the current file could not be kept as a version (versions_dir).");
            }
            $snapshot = ['original_name' => $asset->originalName];
            foreach (self::SNAPSHOT as $key) {
                $snapshot[$key] = $asset->toData()[$key];
            }
            array_unshift($versions, new AssetVersion($asset->version, $rel, $asset->mime, $asset->size, $asset->width, $asset->height, $snapshot, ($this->now)()->format(DATE_ATOM), $by));
        } // else the file was already lost: there is nothing to keep, the new one fixes the asset

        [$width, $height] = self::dimensions($target, $mime);
        $data = [
            'path' => $path,
            'original_name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
            'mime' => $mime,
            'size' => strlen($bytes),
            'width' => $width,
            'height' => $height,
            'duration' => null,
            'version' => $number,
            'versions' => array_map(static fn (AssetVersion $v) => $v->toArray(), $versions),
            'variants' => [],
            'preview' => [],
            'master' => [],
            'color' => '',
            'palette' => [],
            'text_color' => '',
            'note' => '',
        ] + $fields + $asset->toData();
        foreach (self::SNAPSHOT as $key) {
            if (!array_key_exists($key, $restore)) {
                continue; // a version made before this key existed leaves it as it is
            }
            $value = $restore[$key];
            if (in_array($key, ['focal_x', 'focal_y'], true)) {
                if (is_numeric($value)) {
                    $data[$key] = max(0, min(100, (int) $value));
                }
            } elseif ($key === 'rotation') {
                if (is_numeric($value)) {
                    $data[$key] = ((int) $value % 4 + 4) % 4;
                }
            } elseif ($key === 'flip') {
                if (is_string($value)) {
                    $data[$key] = $value === 'h' ? 'h' : '';
                }
            } elseif (is_array($value)) {
                $data[$key] = $value;
            }
        }
        try {
            $saved = $this->save($data);
        } catch (\Throwable $e) {
            @unlink($target);
            if ($kept !== null) {
                @unlink($kept);
            }
            throw $e;
        }

        // The record points at the new file: the old one (now a version), its variants and preview go.
        $this->removeFiles($asset);
        try {
            return $this->afterFileChange($saved);
        } catch (\Throwable $e) {
            // The replace stands (derived files can be made later), but the failure is logged and shown on the asset.
            error_log("preflow/media: after replacing asset {$id}: " . $e::class . ': ' . $e->getMessage());
            $data = ($this->find($id) ?? $saved)->toData();
            $data['note'] = mb_substr('Variants could not be made after the replace: ' . $e::class . ': ' . $e->getMessage(), 0, 255);
            try {
                return $this->save($data);
            } catch (\Throwable) {
                return $this->find($id) ?? $saved;
            }
        }
    }

    /**
     * With the lock held: remove the versions $pick returns from the record, then
     * delete their files. Returns how many were removed.
     *
     * @param \Closure(Asset): list<int> $pick version numbers
     */
    private function dropVersions(string $id, \Closure $pick): int
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return 0;
        }
        $numbers = $pick($asset);
        $gone = array_values(array_filter($asset->versions, static fn (AssetVersion $v) => in_array($v->number, $numbers, true)));
        if ($gone === []) {
            return 0;
        }
        $files = array_filter(array_map(fn (AssetVersion $v) => $this->versionFile($id, $v->number), $gone));
        $data = $asset->toData();
        $data['versions'] = array_values(array_map(
            static fn (AssetVersion $v) => $v->toArray(),
            array_filter($asset->versions, static fn (AssetVersion $v) => !in_array($v->number, $numbers, true)),
        ));
        $this->save($data); // first: the record never lists a missing file
        foreach ($files as $file) {
            @unlink($file);
        }

        return count($gone);
    }

    private static function versionOf(Asset $asset, int $number): ?AssetVersion
    {
        foreach ($asset->versions as $v) {
            if ($v->number === $number) {
                return $v;
            }
        }

        return null;
    }

    /** @throws AssetUploadException without a versions dir: a replace would lose the old file */
    private function requireVersionsDir(string $id, string $name): void
    {
        $this->find($id) ?? throw new \InvalidArgumentException("Unknown asset {$id}.");
        if ($this->versionsDir() === null) {
            throw new AssetUploadException("{$name}: no versions directory is configured (versions_dir), so the current file could not be kept.");
        }
    }

    /**
     * Run $work holding the asset's lock ({versions_dir}/.locks/{id}.lock), so replaces,
     * restores, version deletes, prunes, edits, re-made derived files and the delete of
     * one asset run one after another, each on the record the previous one saved.
     *
     * @template T
     * @param \Closure(): T $work
     * @param bool $required false: without a writable versions dir, run unlocked (a delete still works)
     * @return T
     */
    private function locked(string $id, \Closure $work, bool $required = true): mixed
    {
        $folder = $this->versionsDir() . '/' . self::LOCKS;
        $lock = is_dir($folder) || @mkdir($folder, 0775, true) || is_dir($folder) ? @fopen($folder . '/' . $id . '.lock', 'c') : false;
        if ($lock === false) {
            if (!$required) {
                return $work();
            }
            throw new AssetUploadException('The versions directory is not writable (versions_dir).');
        }
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new AssetUploadException("The lock of asset {$id} could not be taken (versions_dir); nothing was changed.");
        }
        try {
            return $work();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** $work under the asset's lock; without a versions dir (no lock to take), unlocked. */
    private function guarded(string $id, \Closure $work): mixed
    {
        return $this->versionsDir() !== null ? $this->locked($id, $work, required: false) : $work();
    }

    private function removeVersionsFolder(string $id): void
    {
        $dir = $this->versionsDir();
        if ($dir === null || !is_dir($dir . '/' . $id)) {
            return;
        }
        foreach (scandir($dir . '/' . $id) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $id . '/' . $entry)) {
                unlink($dir . '/' . $id . '/' . $entry);
            }
        }
        @rmdir($dir . '/' . $id);
    }

    /** Write $bytes (or copy $from) to a temp file and rename it into place, so the name never holds half a file. */
    private static function writeAtomically(string $dest, ?string $bytes, ?string $from = null): bool
    {
        if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0775, true) && !is_dir(dirname($dest))) {
            return false;
        }
        $tmp = $dest . '.tmp-' . bin2hex(random_bytes(4));
        $ok = $from !== null ? @copy($from, $tmp) : @file_put_contents($tmp, (string) $bytes) === strlen((string) $bytes);
        if (!$ok || !@rename($tmp, $dest)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** The record, its file (with variants and preview) and every version. */
    private function remove(Asset $asset): void
    {
        $work = function () use ($asset): void {
            $current = $this->find($asset->id) ?? $asset; // a replace may have run while we waited
            $this->removeFiles($current);
            $this->removeLeftovers($current);
            $this->dm->deleteIn($this->definition(), $asset->id);
            $this->removeVersionsFolder($asset->id);
            if ($this->versionsDir() !== null) {
                @unlink($this->versionsDir() . '/' . self::LOCKS . '/' . $asset->id . '.lock');
            }
        };
        $this->guarded($asset->id, $work);
    }

    /**
     * Leftovers of earlier files ({id}-v2-480.webp made on demand during a replace, a preview
     * whose record was never saved), in the media or private dir: only this id's names.
     */
    private function removeLeftovers(Asset $asset): void
    {
        $base = $asset->private ? $this->privateDir() : rtrim($this->dir, '/');
        if ($base === null || preg_match('#^\d{4}/\d{2}$#', dirname($asset->path)) !== 1) {
            return;
        }
        foreach (glob($base . '/' . dirname($asset->path) . '/' . $asset->id . '-*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** The asset's current file, its variants (also those made on demand), its preview and the files made from its edits. */
    private function removeFiles(Asset $asset): void
    {
        $files = [$this->file($asset), $asset->preview !== null ? $this->fileAt($asset, $asset->preview->path) : null];
        if (!$asset->private) {
            $files = [...$files, ...array_map(fn (Variant $v) => $this->absolute($v->path), $asset->variants)];
        }
        foreach ($files as $file) {
            if ($file !== null && is_file($file)) {
                unlink($file);
            }
        }
        if (!$asset->private) {
            $this->removeDerived($asset);
            $preview = $this->previewAsset($asset);
            if ($preview !== null) {
                $this->variants?->remove($preview, $this->dir); // also the preview's variants made on demand
                $this->variants?->removeBoxes($preview, $this->dir); // and its box files
            }
        }
    }

    /**
     * The file of one variant, made now if missing. Only widths in $allowed
     * (and the original width, which srcsets list when it is narrower than
     * the largest) and never wider than the original; null for anything else.
     * Concurrent first requests wait on a per-file lock, so it is encoded once.
     *
     * @param list<int> $allowed
     */
    public function variantFile(Asset $asset, int $width, string $format, array $allowed): ?string
    {
        if (!$this->allowsVariant($asset, $width, $format, $allowed)) {
            return null;
        }

        $rel = substr($asset->path, 0, (int) strrpos($asset->path, '.')) . "-{$width}.{$format}";

        return $this->madeOnce($rel, fn () => $this->variants?->generateOne($this->dir, $asset->path, $asset->mime, $width, $format));
    }

    /**
     * The file at $rel under the media dir, made by $make if missing. A per-file lock makes
     * concurrent first requests wait for the one making it. Null when it is still missing.
     *
     * @param \Closure(): mixed $make
     */
    private function madeOnce(string $rel, \Closure $make): ?string
    {
        $file = $this->absolute($rel);
        if (is_file($file)) {
            return $file;
        }
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            return null;
        }
        $lock = @fopen($file . '.lock', 'c');
        if ($lock === false) {
            return null;
        }
        try {
            flock($lock, LOCK_EX);
            clearstatcache(true, $file);
            if (!is_file($file)) {
                $make();
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($file . '.lock');
        }

        return is_file($file) ? $file : null;
    }

    /**
     * Whether a variant may exist: an allowed width (or the original width when
     * it is narrower than the largest), never wider than the original, for a
     * format the asset already has variants in (so a srcset lists it) and the
     * backend can still write.
     *
     * @param list<int> $allowed
     */
    public function allowsVariant(Asset $asset, int $width, string $format, array $allowed): bool
    {
        if ($this->variants === null || $asset->private || $asset->width === null || $asset->height === null || $width > $asset->width
            || !$this->variants->supports($asset->mime, $format)
            || array_filter($asset->variants, static fn (Variant $v) => $v->format === $format) === []
            || $asset->width * $asset->height > (int) ($this->config['max_pixels'] ?? self::DEFAULT_MAX_PIXELS)) {
            return false;
        }
        $isOriginal = $width === $asset->width && $allowed !== [] && $width < max($allowed);

        return in_array($width, $allowed, true) || $isOriginal;
    }

    /** The closest variant made at upload (the largest not wider, else the smallest), else the original. */
    public function nearestVariant(Asset $asset, int $width, string $format): string
    {
        $made = array_values(array_filter($asset->variants, fn (Variant $v) => $v->format === $format && is_file($this->absolute($v->path))));
        usort($made, static fn (Variant $a, Variant $b) => $a->width <=> $b->width);
        $fit = array_values(array_filter($made, static fn (Variant $v) => $v->width <= $width));

        return ($fit !== [] ? end($fit) : ($made[0] ?? null))?->path ?? $asset->path;
    }

    /**
     * Make every allowed variant the delivery image can fill, and every box preset's files,
     * in advance (media:variants --all). Returns how many were new.
     *
     * @param list<int> $allowed
     */
    public function warm(Asset $asset, array $allowed): int
    {
        $made = 0;
        $delivery = $asset->private ? null : $this->deliveryAsset($asset);
        if ($delivery === null) {
            return 0;
        }
        foreach (['avif', 'webp'] as $format) {
            foreach ($allowed as $width) {
                $rel = self::stemOf($delivery->path) . "-{$width}.{$format}";
                $had = is_file($this->absolute($rel));
                if ($this->variantFile($delivery, $width, $format, $allowed) !== null && !$had) {
                    $made++;
                }
            }
        }
        foreach (array_keys($this->formats?->boxes() ?? []) as $preset) {
            $plan = $this->boxPlan($asset, $preset);
            foreach ($plan === null ? [] : ['avif', 'webp'] as $format) {
                if (array_filter($delivery->variants, static fn (Variant $v) => $v->format === $format) === []) {
                    continue; // pages list only the formats the image has variants in
                }
                foreach ($plan->widths as $width) {
                    $had = is_file($this->absolute("{$plan->base}-{$width}.{$format}"));
                    if ($this->boxFile($plan, $width, $format) !== null && !$had) {
                        $made++;
                    }
                }
            }
        }

        return $made;
    }

    /**
     * Replace an image's variants (e.g. after the configured widths changed), and render an
     * edited image's master again. Works on the stored record under the asset's lock, so a
     * replace since $asset was read stands.
     */
    public function regenerate(Asset $asset): Asset
    {
        if ($this->variants === null || $asset->private || $asset->kind() !== 'image') {
            return $asset;
        }

        return $this->guarded($asset->id, function () use ($asset): Asset {
            $current = $this->find($asset->id); // a replace may have run since $asset was read
            if ($current === null) {
                return $asset; // deleted since: saving would bring the record back without its file
            }
            if ($current->private || $current->kind() !== 'image') {
                return $current;
            }
            // The master (when edited) and the variants are made again; the colours stay.
            return $this->remakeDerived($current, colors: false);
        });
    }

    /**
     * Make a document's preview (and a public one's preview variants) again from its
     * current file, e.g. for documents uploaded before previews existed (media:previews).
     * The new preview is rendered to a temp file first and only then swapped in (record
     * saved, then the old preview's leftovers removed), so a render that fails or is
     * killed keeps the working preview. Null, with the asset unchanged, when nothing was
     * rendered (an image, no renderer for the type, a broken file, the time limit).
     */
    public function remakePreview(Asset $asset): ?Asset
    {
        if ($asset->kind() === 'image' || $this->previews?->for($asset->mime) === null) {
            return null;
        }

        return $this->guarded($asset->id, function () use ($asset): ?Asset {
            $current = $this->find($asset->id); // a replace may have run while we waited
            $rendered = $current !== null ? $this->renderPreview($current) : null; // null: deleted since
            if ($rendered === null) {
                return null;
            }
            [$tmp, $width, $height] = $rendered;
            $path = substr($current->path, 0, (int) strrpos($current->path, '.')) . '-preview.png';
            $old = [$current->preview !== null && $current->preview->path !== $path ? $this->fileAt($current, $current->preview->path) : null];
            if (!$current->private) {
                $old = [...$old, ...array_map(fn (Variant $v) => $this->absolute($v->path), $current->variants)];
            }
            $oldPreview = $this->previewAsset($current);
            $saved = $this->placePreview($current, $tmp, $path, $width, $height, ['variants' => [], 'color' => '', 'palette' => [], 'text_color' => '']);
            if ($saved === null) {
                return null;
            }
            // The record is on the new preview: the old one's variants (also those made on demand) go.
            if (!$current->private && $oldPreview !== null) {
                $this->variants?->remove($oldPreview, $this->dir);
                $this->variants?->removeBoxes($oldPreview, $this->dir); // same names for the new preview: they would be stale
            }
            foreach ($old as $file) {
                if ($file !== null && is_file($file)) {
                    unlink($file);
                }
            }

            return $this->afterFileChange($saved, withPreview: false);
        });
    }

    /** The URL of an asset (an edited image: its master; a private one: only the gated {private_url}/{id}) or of a path under the media URL. */
    public function url(Asset|string $target): string
    {
        if ($target instanceof Asset) {
            if ($target->private) {
                return rtrim((string) ($this->config['private_url'] ?? '/files'), '/') . '/' . $target->id;
            }
            // An edited image is delivered as its rendered master.
            $target = $target->kind() === 'image' ? ($this->deliveryAsset($target) ?? $target)->path : $target->path;
        }

        return rtrim($this->url, '/') . '/' . ltrim($target, '/');
    }

    /** The URL of the asset's own file, never the rendered master (for an unedited asset the same as url()). */
    public function originalUrl(Asset $asset): string
    {
        return $asset->private ? $this->url($asset) : $this->url($asset->path);
    }

    /** The private directory (config "private_dir"); null when none is configured. */
    public function privateDir(): ?string
    {
        $dir = $this->config['private_dir'] ?? null;

        return is_string($dir) && $dir !== '' ? rtrim($dir, '/') : null;
    }

    /** The asset's file on disk (unchecked; the private route confines it with realpath); null for a private asset without a private dir. */
    public function file(Asset $asset): ?string
    {
        return $this->fileAt($asset, $asset->path);
    }

    /** A path relative to where the asset's files live: the media dir, or the private dir for a private asset. */
    private function fileAt(Asset $asset, string $relativePath): ?string
    {
        if (!$asset->private) {
            return $this->absolute($relativePath);
        }
        $dir = $this->privateDir();

        return $dir === null ? null : $dir . '/' . ltrim($relativePath, '/');
    }

    /** The versions directory (config "versions_dir"); null when none is configured, and then nothing can be replaced. */
    public function versionsDir(): ?string
    {
        $dir = $this->config['versions_dir'] ?? null;

        return is_string($dir) && $dir !== '' ? rtrim($dir, '/') : null;
    }

    public function absolute(string $relativePath): string
    {
        return rtrim($this->dir, '/') . '/' . ltrim($relativePath, '/');
    }

    /**
     * A small preview URL: the 480 (else smallest) WebP variant, else the original image; for a
     * video or document its image poster's, else its preview's (a private one: the gated preview
     * URL); null for other files.
     */
    public function thumbnailUrl(Asset $asset): ?string
    {
        if ($asset->kind() !== 'image') {
            $poster = $asset->posterId !== null && $asset->posterId !== $asset->id ? $this->find($asset->posterId) : null;
            if ($poster !== null && $poster->kind() === 'image') {
                return $this->thumbnailUrl($poster);
            }
            $preview = $this->previewAsset($asset);
            if ($preview === null || $asset->private) {
                return $this->previewUrl($asset);
            }
            $asset = $preview;
        } else {
            $asset = $this->deliveryAsset($asset) ?? $asset;
        }
        if ($asset->private) {
            return $this->url($asset); // gated: staff need the private permission (or "*") to see it
        }
        $webp = array_filter($asset->variants, static fn (Variant $v) => $v->format === 'webp');
        usort($webp, static fn (Variant $a, Variant $b) => [$a->width !== 480, $a->width] <=> [$b->width !== 480, $b->width]);

        return $this->url(($webp[0] ?? null)?->path ?? $asset->path);
    }

    /**
     * A document's preview as an image asset: the preview's path, type and size, and the
     * asset's variants (a document's variants are its preview's). Null without a preview,
     * or when the recorded one is not the current file's ({stem}-preview.png).
     */
    public function previewAsset(Asset $asset): ?Asset
    {
        $preview = $asset->preview;
        $stem = substr($asset->path, 0, (int) strrpos($asset->path, '.'));
        if ($preview === null || $asset->kind() === 'image' || $stem === '' || $preview->path !== $stem . '-preview.png') {
            return null;
        }

        return new Asset(...[...get_object_vars($asset), 'path' => $preview->path, 'mime' => 'image/png', 'width' => $preview->width, 'height' => $preview->height]);
    }

    /**
     * The image a page shows: an edited image's rendered master (its path, type and size, with
     * the asset's variants, which are the master's), an image without one itself, a document's
     * preview (previewAsset()); null for anything else.
     */
    public function deliveryAsset(Asset $asset): ?Asset
    {
        if ($asset->kind() !== 'image') {
            return $this->previewAsset($asset);
        }
        $master = $asset->master;
        // Only the current file's master ({stem}-edit-…): a record cannot point anywhere else.
        if ($master === null || !$asset->isEdited() || !str_starts_with($master->path, self::stemOf($asset->path) . '-edit-')) {
            return $asset;
        }
        $mime = array_search(pathinfo($master->path, PATHINFO_EXTENSION), ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], true);

        return new Asset(...[...get_object_vars($asset), 'path' => $master->path, 'mime' => (string) $mime, 'width' => $master->width, 'height' => $master->height]);
    }

    /**
     * Render an edited image's master ({stem}-edit-{key}.{ext}, the key from the version and
     * the edits) and save it on the asset. Without edits, or when it cannot be made, the asset
     * stays as it is (then pages keep the file); a source that needs Imagick where it is
     * missing gets the note.
     */
    private function renderMaster(Asset $asset): Asset
    {
        if (!$asset->isEdited() || !$this->isEditable($asset)) {
            return $asset;
        }
        if ($this->variants === null || !$this->withinMaxPixels($asset)) {
            error_log("preflow/media: no edited image of asset {$asset->id}: " . ($this->variants === null ? 'no variants backend' : 'above max_pixels'));

            return $asset;
        }
        $path = self::stemOf($asset->path) . '-edit-' . Formats::key([$asset->version, $asset->rotation, $asset->mirrored, $asset->crop?->toArray()])
            . '.' . self::MASTER_EXTENSIONS[$asset->mime];
        try {
            $size = $this->variants->renderEdited($this->dir, $asset->path, $asset->mime, $asset->rotation, $asset->mirrored, $asset->crop, $path);
        } catch (\Throwable $e) {
            error_log("preflow/media: no edited image of asset {$asset->id}: " . $e::class . ': ' . $e->getMessage());
            $size = null;
        }
        $data = $asset->toData();
        if ($size !== null) {
            $data['master'] = (new Preview($path, $size[0], $size[1]))->toArray();
        } elseif ($this->lacksColorManagement($asset)) {
            $data['note'] = self::NOTE_NEEDS_IMAGICK; // never shown in wrong colours: the file stays what pages get
        } else {
            return $asset;
        }

        return $this->save($data);
    }

    /**
     * Delete what was made from a public asset's file and edits: the file's variants, its box
     * files, the rendered master with its variants and box files, the editor's image. The
     * file itself and a document's preview stay.
     */
    private function removeDerived(Asset $asset): void
    {
        if ($asset->private) {
            return;
        }
        $this->variants?->remove($asset, $this->dir);
        $this->variants?->removeBoxes($asset, $this->dir);
        $stem = $this->absolute(self::stemOf($asset->path));
        $own = '/^' . preg_quote(basename($stem), '/') . '-(edit-[0-9a-f]{8}(\.(jpg|png|webp)|-[a-z0-9-]+\.(avif|webp))|oriented-[0-9a-f]{8}\.webp)$/';
        foreach ([...(glob($stem . '-edit-*') ?: []), ...(glob($stem . '-oriented-*') ?: [])] as $file) {
            if (preg_match($own, basename($file)) === 1 && is_file($file)) {
                unlink($file);
            }
        }
    }

    /** Delete the box files of the delivery image (they are made again on their next request). */
    private function removeBoxes(Asset $asset): void
    {
        $delivery = $asset->private ? null : $this->deliveryAsset($asset);
        if ($delivery !== null) {
            $this->variants?->removeBoxes($delivery, $this->dir);
        }
    }

    private function withinMaxPixels(Asset $asset): bool
    {
        return $asset->width !== null && $asset->height !== null
            && $asset->width * $asset->height <= (int) ($this->config['max_pixels'] ?? self::DEFAULT_MAX_PIXELS);
    }

    /** A relative path without its extension (Y/m/{id} or Y/m/{id}-v{n}). */
    private static function stemOf(string $path): string
    {
        $dot = strrpos($path, '.');

        return $dot === false ? $path : substr($path, 0, $dot);
    }

    /** The preview's URL: under the media URL, or the gated {private_url}/{id}/preview of a private asset; null without one. */
    public function previewUrl(Asset $asset): ?string
    {
        $preview = $this->previewAsset($asset);
        if ($preview === null) {
            return null;
        }

        return $asset->private
            ? rtrim((string) ($this->config['private_url'] ?? '/files'), '/') . '/' . $asset->id . '/preview'
            : $this->url($preview->path);
    }

    /** The preview's file on disk (unchecked, as file()); null without a preview. */
    public function previewFile(Asset $asset): ?string
    {
        $preview = $this->previewAsset($asset);

        return $preview === null ? null : $this->fileAt($asset, $preview->path);
    }

    /** True for the id of an existing asset (never for a path). */
    public function isAssetId(string $value): bool
    {
        return $this->find($value) !== null;
    }

    /**
     * @param array<string, mixed> $fields record fields set from the start (a copy's link and edits)
     * @throws AssetUploadException
     */
    private function store(string $bytes, string $name, ?string $by, bool $private = false, array $fields = []): Asset
    {
        [$mime, $ext] = $this->sniff($bytes, $name);

        $now = ($this->now)();
        $id = bin2hex(random_bytes(16));
        $path = $now->format('Y/m') . '/' . $id . '.' . $ext;
        if ($private && $this->privateDir() === null) {
            throw new AssetUploadException("{$name}: no private directory is configured (assets.private_dir).");
        }
        $file = $private ? $this->privateDir() . '/' . $path : $this->absolute($path);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
            throw new AssetUploadException('The media directory is not writable.');
        }
        if (file_put_contents($file, $bytes) !== strlen($bytes)) {
            @unlink($file);
            throw new AssetUploadException("{$name}: the file could not be written to the media directory.");
        }

        [$width, $height] = self::dimensions($file, $mime);

        $asset = new Asset(
            id: $id,
            path: $path,
            originalName: mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
            mime: $mime,
            size: strlen($bytes),
            width: $width,
            height: $height,
            duration: null,
            alt: [],
            title: '',
            focalX: 50,
            focalY: 50,
            posterId: null,
            variants: [],
            createdAt: $now->format(DATE_ATOM),
            createdBy: $by,
            private: $private,
            extra: $this->extraDefaults(),
        );
        // The record first: if variant generation dies (time or memory limit), the
        // file is still a usable asset whose variants can be made later.
        return $this->afterFileChange($this->save($fields + $asset->toData()));
    }

    /**
     * The hook for everything derived from the asset's current file, run once the
     * record points at the complete file: after an upload, a replace and a restore.
     * It makes a document's preview, then delivers it (deliver()). Returns the asset as saved.
     */
    private function afterFileChange(Asset $asset, bool $withPreview = true): Asset
    {
        return $this->deliver($withPreview ? $this->makePreview($asset) : $asset);
    }

    /**
     * What pages get of the asset: the rendered master of an edited image, the colours
     * (unless $colors is false), then the variants of the delivery image (a public image,
     * its master, or a public document's preview). Returns the asset as saved.
     */
    private function deliver(Asset $asset, bool $colors = true): Asset
    {
        $asset = $this->renderMaster($asset);
        if ($colors) {
            $asset = $this->readColors($asset);
        }
        if ($asset->private) {
            return $asset; // no variants: they would be public files
        }
        $source = $this->deliveryAsset($asset);
        if ($source === null) {
            return $asset;
        }
        $data = $asset->toData();
        $data['variants'] = array_map(static fn (Variant $v) => $v->toArray(), $this->generateVariants($source));
        if ($this->lacksColorManagement($source)) {
            // Never shown in wrong colours: the original only, and the reason on the asset.
            $data['note'] = self::NOTE_NEEDS_IMAGICK;
        } elseif ($data['variants'] === []) {
            return $asset;
        }
        $asset = $this->save($data);
        // Optional: make the step widths now, so a page full of new images does not
        // start many encodes at once on its first view (assets.warm_on_upload).
        if (($this->config['warm_on_upload'] ?? false) === true && is_array($this->config['warm_widths'] ?? null)) {
            $this->warm($asset, array_values(array_map('intval', $this->config['warm_widths'])));
        }

        return $asset;
    }

    /**
     * Read an asset's colours again from its current file (a document's from its
     * preview), e.g. for assets uploaded before colours existed (media:colors). Null,
     * with the asset unchanged, when they cannot be read (no extractor, not an image,
     * no preview, an unreadable file, or a colour profile that needs Imagick).
     */
    public function extractColors(Asset $asset): ?Asset
    {
        $work = function () use ($asset): ?Asset {
            $current = $this->find($asset->id); // a replace may have run while we waited
            $colors = $current !== null ? $this->colorsOf($current) : null; // null: deleted since

            return $colors !== null ? $this->withColors($current, $colors) : null;
        };

        return $this->guarded($asset->id, $work);
    }

    /** The colours read and saved on the asset; any failure leaves it as it is (the upload stands). */
    private function readColors(Asset $asset): Asset
    {
        $colors = $this->colorsOf($asset);

        return $colors !== null ? $this->withColors($asset, $colors) : $asset;
    }

    /**
     * The colours of the delivery image (an image's file or master, a document's preview);
     * null without them. A failure is logged.
     *
     * @return ?array{color: string, palette: list<string>, text: string}
     */
    private function colorsOf(Asset $asset): ?array
    {
        if ($this->colors === null) {
            return null;
        }
        $source = $this->deliveryAsset($asset); // an edited image: its master
        if ($source === null || $source->mime === 'image/svg+xml' || $source->width === null || $source->height === null
            || $source->width * $source->height > (int) ($this->config['max_pixels'] ?? self::DEFAULT_MAX_PIXELS)) {
            return null;
        }
        $file = $this->fileAt($asset, $source->path);
        if ($file === null || !is_file($file)) {
            return null;
        }
        try {
            return $this->colors->fromFile($file);
        } catch (\Throwable $e) {
            error_log("preflow/media: no colours of asset {$asset->id}: " . $e::class . ': ' . $e->getMessage());

            return null;
        }
    }

    /** @param array{color: string, palette: list<string>, text: string} $colors */
    private function withColors(Asset $asset, array $colors): Asset
    {
        $data = $asset->toData();
        $data['color'] = $colors['color'];
        $data['palette'] = $colors['palette'];
        $data['text_color'] = $colors['text'];

        return $this->save($data);
    }

    /**
     * A document's preview from the renderer for its type, stored beside its file as
     * {stem}-preview.png. The renderer only ever gets the asset's own path. Any failure
     * (no renderer, a broken or encrypted file, the time limit) leaves the asset without
     * a preview; the upload stands.
     */
    private function makePreview(Asset $asset): Asset
    {
        $rendered = $this->renderPreview($asset);
        if ($rendered === null) {
            return $asset;
        }
        $path = substr($asset->path, 0, (int) strrpos($asset->path, '.')) . '-preview.png';

        return $this->placePreview($asset, $rendered[0], $path, $rendered[1], $rendered[2]) ?? $asset;
    }

    /**
     * The asset's preview rendered to a temp PNG (the caller moves or removes it), with
     * its size; null when there is no renderer or nothing usable came out (logged).
     *
     * @return ?array{0: string, 1: int, 2: int}
     */
    private function renderPreview(Asset $asset): ?array
    {
        $renderer = $asset->kind() === 'image' ? null : $this->previews?->for($asset->mime);
        $file = $renderer !== null ? $this->file($asset) : null;
        if ($renderer === null || $file === null || !is_file($file)) {
            return null;
        }
        $longSide = min(self::PREVIEW_LONG_SIDE, (int) floor(sqrt(max(1, (int) ($this->config['max_pixels'] ?? self::DEFAULT_MAX_PIXELS)))));
        try {
            $tmp = $renderer->render($file, $longSide);
        } catch (\Throwable $e) {
            error_log("preflow/media: no preview of asset {$asset->id}: " . $e::class . ': ' . $e->getMessage());

            return null;
        }
        if ($tmp === null) {
            return null;
        }
        $size = @getimagesize($tmp);
        if (!is_array($size) || $size[2] !== IMAGETYPE_PNG) {
            @unlink($tmp);

            return null;
        }

        return [$tmp, (int) $size[0], (int) $size[1]];
    }

    /**
     * Move a rendered temp PNG to $path beside the asset's file (atomically, so an old
     * preview of that name is swapped) and save it on the record with $extra. Null when
     * it could not be written. The temp file is gone afterwards either way.
     *
     * @param array<string, mixed> $extra
     */
    private function placePreview(Asset $asset, string $tmp, string $path, int $width, int $height, array $extra = []): ?Asset
    {
        try {
            $dest = $this->fileAt($asset, $path);
            if ($dest === null || !self::writeAtomically($dest, null, $tmp)) {
                return null;
            }
        } finally {
            @unlink($tmp);
        }
        $data = $extra + $asset->toData();
        $data['preview'] = (new Preview($path, $width, $height))->toArray();

        return $this->save($data);
    }

    /**
     * The sniffed MIME type and the extension it is stored with (never the client's).
     *
     * @return array{0: string, 1: string}
     * @throws AssetUploadException for a type that is not allowed
     */
    private function sniff(string $bytes, string $name): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        if ($mime === 'image/svg+xml' && ($this->config['allow_svg'] ?? false) !== true) {
            throw new AssetUploadException('SVG uploads are disabled.');
        }
        $ext = self::EXTENSIONS[$mime] ?? null;
        if ($ext === null) {
            throw new AssetUploadException("{$name}: files of type {$mime} are not allowed.");
        }

        return [$mime, $ext];
    }

    /** @return array{0: ?int, 1: ?int} an image's width and height as displayed (EXIF orientation applied) */
    private static function dimensions(string $file, string $mime): array
    {
        if (!str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return [null, null];
        }
        $size = @getimagesize($file);
        if (!is_array($size)) {
            return [null, null];
        }
        [$width, $height] = [(int) $size[0], (int) $size[1]];

        return Orientation::swapsAxes(Orientation::of($file)) ? [$height, $width] : [$width, $height];
    }

    /** The relative path of an asset's file: Y/m/{id}.{ext} for version 1, Y/m/{id}-v{n}.{ext} after that. */
    public function versionedPath(string $id, string $ext, int $version, string $yearMonth): string
    {
        return $yearMonth . '/' . $id . ($version > 1 ? '-v' . $version : '') . '.' . $ext;
    }

    /** @return list<Variant> */
    private function generateVariants(Asset $asset): array
    {
        if ($this->variants === null || $asset->width === null || $asset->height === null) {
            return [];
        }
        if ($asset->width * $asset->height > (int) ($this->config['max_pixels'] ?? self::DEFAULT_MAX_PIXELS)) {
            return [];
        }
        try {
            return $this->variants->generate($this->dir, $asset->path, $asset->mime, $asset->width);
        } catch (\Throwable) {
            $this->variants->remove($asset, $this->dir); // no half-written files without a record
            return [];
        }
    }

    /** A CMYK or wide-gamut image on a site without Imagick (with lcms): no variants rather than wrong colours. */
    private function lacksColorManagement(Asset $asset): bool
    {
        return $this->variants !== null && !$asset->private && $this->variants->lacksColorManagement($this->absolute($asset->path), $asset->mime);
    }

    /** @param array<string, mixed> $data */
    private function save(array $data): Asset
    {
        $record = DynamicRecord::fromArray($this->definition(), $data);
        $this->dm->saveType($record, false);

        return Asset::fromRecord($record);
    }

    /** @throws AssetUploadException */
    private function checkSize(string $name, int $bytes): void
    {
        $limit = (int) ($this->config['max_bytes'] ?? 20 * 1024 * 1024);
        if ($bytes > $limit) {
            throw new AssetUploadException("{$name} is larger than the " . self::humanBytes($limit) . ' limit.');
        }
    }

    private static function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024) . ' KB',
            default => $bytes . ' B',
        };
    }
}
