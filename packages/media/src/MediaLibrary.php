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
use Preflow\Media\Image\Orientation;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\Preview\PreviewRenderers;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
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
 * A document a preview renderer supports (a PDF) gets a preview of its first page
 * on upload, replace and restore: {stem}-preview.png beside its file, in the private
 * dir for a private asset (served only at the gated {private_url}/{id}/preview).
 * A public document's variants are its preview's ({stem}-preview-{w}.{fmt}).
 *
 * With a colour extractor, an image's colours (dominant colour, palette, text colour)
 * are read on upload, replace and restore from the image as people see it (sRGB),
 * a document's from its preview. A failure leaves the asset without colours.
 *
 * Deleting refuses an asset that a registered usage source still reports
 * (AssetInUseException), so every UI gets the rule.
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
     * @throws AssetUploadException
     */
    public function importFile(string $absPath, string $originalName, ?string $by): Asset
    {
        return $this->store($this->readFile($absPath, $originalName), $originalName, $by);
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

    public function find(string $id): ?Asset
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }
        $record = $this->dm->findIn($this->definition(), $id);

        return $record === null ? null : Asset::fromRecord($record);
    }

    /** @return list<Asset> newest first */
    public function all(): array
    {
        $assets = array_map(Asset::fromRecord(...), array_values($this->dm->queryIn($this->definition())->get()->items()));
        usort($assets, static fn (Asset $a, Asset $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);

        return $assets;
    }

    /**
     * One page of assets, newest first. $kind is image, video or document (null: all); $search
     * matches every word in the original name, title or alt text (in any locale). $filters
     * compare by equality on the extra fields declared in config "filters" (others are ignored):
     * a select or boolean field by value, a relation field by id (a list: any of them), a
     * multiple relation as "contains the id". The asset's own "private" flag filters the same
     * way as a boolean (a picker that lists only what its field accepts). A page past the end
     * gives the last page.
     *
     * @param array<string, mixed> $filters field name => value
     */
    public function query(?string $kind = null, string $search = '', array $filters = [], int $page = 1, int $perPage = 50): AssetPage
    {
        $def = $this->definition();
        $qb = $this->dm->queryIn($def)->locale($this->locales->default);

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
        $qb->orderBy('created_at', SortDirection::Desc)->orderBy($def->idField, SortDirection::Desc);

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

        return $this->save($data);
    }

    /** @throws AssetInUseException while a usage source reports the asset (nothing is deleted) */
    public function delete(string $id): void
    {
        $asset = $this->find($id);
        if ($asset === null) {
            return;
        }
        $usages = $this->usage?->of($id) ?? [];
        if ($usages !== []) {
            throw new AssetInUseException($id, $usages);
        }
        $this->remove($asset);
    }

    /**
     * Delete without asking the usage sources. Only for an asset created in this
     * request that nothing can reference yet (an upload a field then refuses).
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
     * @throws AssetUploadException
     */
    private function swapFile(string $id, string $bytes, string $name, ?string $by, array $restore): Asset
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
        ] + $asset->toData();
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
            // Leftovers of earlier files ({id}-v2-480.webp made on demand during a replace, a preview
            // whose record was never saved), in the media or private dir: only this id's names.
            $base = $current->private ? $this->privateDir() : rtrim($this->dir, '/');
            if ($base !== null && preg_match('#^\d{4}/\d{2}$#', dirname($current->path)) === 1) {
                foreach (glob($base . '/' . dirname($current->path) . '/' . $current->id . '-*') ?: [] as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            $this->dm->deleteIn($this->definition(), $asset->id);
            $this->removeVersionsFolder($asset->id);
            if ($this->versionsDir() !== null) {
                @unlink($this->versionsDir() . '/' . self::LOCKS . '/' . $asset->id . '.lock');
            }
        };
        $this->guarded($asset->id, $work);
    }

    /** The asset's current file, its variants (also those made on demand) and its preview. */
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
            $this->variants?->remove($asset, $this->dir);
            $preview = $this->previewAsset($asset);
            if ($preview !== null) {
                $this->variants?->remove($preview, $this->dir); // also the preview's variants made on demand
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
                $this->variants->generateOne($this->dir, $asset->path, $asset->mime, $width, $format);
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
     * Make every allowed variant the image can fill, in advance
     * (media:variants --all). Returns how many were new.
     *
     * @param list<int> $allowed
     */
    public function warm(Asset $asset, array $allowed): int
    {
        $made = 0;
        foreach (['avif', 'webp'] as $format) {
            foreach ($allowed as $width) {
                $rel = substr($asset->path, 0, (int) strrpos($asset->path, '.')) . "-{$width}.{$format}";
                $had = is_file($this->absolute($rel));
                if ($this->variantFile($asset, $width, $format, $allowed) !== null && !$had) {
                    $made++;
                }
            }
        }

        return $made;
    }

    /**
     * Replace an image's variants (e.g. after the configured widths changed). Works on
     * the stored record under the asset's lock, so a replace since $asset was read stands.
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
            $this->variants->remove($current, $this->dir);
            $data = $current->toData();
            $data['variants'] = array_map(static fn (Variant $v) => $v->toArray(), $this->generateVariants($current));
            $lacks = $this->lacksColorManagement($current);
            if ($lacks || $current->note === self::NOTE_NEEDS_IMAGICK) {
                $data['note'] = $lacks ? self::NOTE_NEEDS_IMAGICK : '';
            }

            return $this->save($data);
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
            }
            foreach ($old as $file) {
                if ($file !== null && is_file($file)) {
                    unlink($file);
                }
            }

            return $this->afterFileChange($saved, withPreview: false);
        });
    }

    /** The URL of an asset (a private one: only the gated {private_url}/{id}) or of a path under the media URL. */
    public function url(Asset|string $target): string
    {
        if ($target instanceof Asset) {
            if ($target->private) {
                return rtrim((string) ($this->config['private_url'] ?? '/files'), '/') . '/' . $target->id;
            }
            $target = $target->path;
        }

        return rtrim($this->url, '/') . '/' . ltrim($target, '/');
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
     * document its preview's (a private one: the gated preview URL); null for other files.
     */
    public function thumbnailUrl(Asset $asset): ?string
    {
        if ($asset->kind() !== 'image') {
            $preview = $this->previewAsset($asset);
            if ($preview === null || $asset->private) {
                return $this->previewUrl($asset);
            }
            $asset = $preview;
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

    /** @throws AssetUploadException */
    private function store(string $bytes, string $name, ?string $by, bool $private = false): Asset
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
        return $this->afterFileChange($this->save($asset->toData()));
    }

    /**
     * The hook for everything derived from the asset's current file, run once the
     * record points at the complete file: after an upload, a replace and a restore.
     * It makes a document's preview, reads the colours, then makes the variants of a
     * public image or of a public document's preview. Returns the asset as saved.
     */
    private function afterFileChange(Asset $asset, bool $withPreview = true): Asset
    {
        $asset = $this->readColors($withPreview ? $this->makePreview($asset) : $asset);
        if ($asset->private) {
            return $asset; // no variants: they would be public files
        }
        $source = $asset->kind() === 'image' ? $asset : $this->previewAsset($asset);
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
            $this->warm($this->previewAsset($asset) ?? $asset, array_values(array_map('intval', $this->config['warm_widths'])));
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
     * The colours of an image's file or a document's preview; null without them. A
     * failure is logged.
     *
     * @return ?array{color: string, palette: list<string>, text: string}
     */
    private function colorsOf(Asset $asset): ?array
    {
        if ($this->colors === null) {
            return null;
        }
        $source = $asset->kind() === 'image' ? $asset : $this->previewAsset($asset);
        if ($source === null || $source->mime === 'image/svg+xml' || $source->width === null || $source->height === null
            || $source->width * $source->height > (int) ($this->config['max_pixels'] ?? self::DEFAULT_MAX_PIXELS)) {
            return null;
        }
        $file = $source === $asset ? $this->file($asset) : $this->previewFile($asset);
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
