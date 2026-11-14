<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Preflow\Data\DataManager;
use Preflow\Data\Driver\PdoDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Content\TypeListing;
use Preflow\Publishing\ShadowTypes;
use Preflow\Folio\Seo\SeoSection;
use Preflow\Media\Usage\AssetReferenceSource;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Media\Usage\CountsAssetUsage;

/**
 * Folio's records as a usage source: working copies of every type, published
 * snapshots (a live page may still show an asset the draft already dropped,
 * note "published version") and earlier revisions (restoring one brings the
 * asset back, note "earlier revision"). A record's share image ("SEO &
 * sharing", on routed types and the site singleton) counts like an asset
 * field. Each record is listed once. A revision does not block a delete: the
 * asset goes into the bin, and cleanup and emptying the bin keep it while a
 * revision needs it. Poster use is the media core's own source
 * (PosterReferences).
 *
 * Runs on every delete, so it reads only what can hold a reference: types
 * without an asset or blocks field or a share image are skipped, and on SQL
 * storage only rows whose asset, blocks or `_seo` columns (a revision's "data")
 * contain the id (LIKE) are walked. A library page counts the usages of all its
 * assets at once (usageCounts(): one query per type and source for the page's ids, the
 * revisions left out unless asked: they hold no usage that blocks a delete).
 * Each entry's type is the record's type key.
 */
final class FolioRecordReferences implements AssetReferenceSource, CountsAssetUsage
{
    public const string NAME = 'folio';
    public const string PUBLISHED = 'published version';
    public const string REVISION = 'earlier revision';

    public function __construct(
        private readonly AssetReferences $references,
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly string $prefix,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    /** @return list<AssetUsageEntry> */
    public function usagesOf(string $assetId): array
    {
        return array_values($this->collect([$assetId], true)[$assetId] ?? []);
    }

    /**
     * The usages of a whole page of assets: the same walk as usagesOf(), with one query per
     * type and source for all the ids (OR-ed LIKEs), counted here. An earlier revision does not
     * block a delete, so the revisions are read only with $nonBlocking: a library page's cards
     * count without them (scanning every revision's data on each page view is the slow part).
     *
     * @param list<string> $assetIds
     * @return array<string, int>
     */
    public function usageCounts(array $assetIds, bool $nonBlocking = false): array
    {
        return array_map('count', $this->collect($assetIds, $nonBlocking));
    }

    /**
     * Each asset's usages, keyed "{type}/{record id}" so a record is listed once per asset: its
     * working copy first, then its published version, then an earlier revision.
     *
     * @param list<string> $assetIds
     * @param bool $revisions read the revisions too (their usages do not block a delete)
     * @return array<string, array<string, AssetUsageEntry>>
     */
    private function collect(array $assetIds, bool $revisions): array
    {
        $ids = array_values(array_unique(array_filter($assetIds, static fn ($id): bool => is_string($id) && $id !== '')));
        $usage = array_fill_keys($ids, []);
        if ($ids === []) {
            return $usage;
        }
        $labeler = new RecordLabeler();
        $shared = $this->sharedTypes();
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            $columns = self::referenceFields($def);
            $seo = isset($shared[$listing->key]);
            if ($columns === [] && !$seo) {
                continue; // AssetReferences reads only asset and blocks fields
            }
            $sources = ['working' => $def];
            if ($listing->publishing) {
                $sources['published'] = ShadowTypes::published($def);
            }
            foreach ($sources as $where => $source) {
                foreach ($this->candidates($source, $columns, $ids, $seo) as $record) {
                    $key = $listing->key . '/' . $record->getId();
                    $label = null;
                    foreach ($this->used($def, $record->toArray(), $usage, $seo) as $assetId) {
                        if (isset($usage[$assetId][$key])) {
                            continue;
                        }
                        $usage[$assetId][$key] = new AssetUsageEntry(
                            $label ??= $labeler->label($record),
                            $this->editUrl($listing, (string) $record->getId()),
                            self::NAME,
                            $where === 'published' ? self::PUBLISHED : null,
                            type: $listing->key,
                        );
                    }
                }
            }
            if ($revisions && $listing->publishing) {
                foreach ($this->candidates(ShadowTypes::revisions($def), ['data'], $ids) as $row) {
                    $key = $listing->key . '/' . (string) $row->get('record_id');
                    $data = json_decode((string) $row->get('data'), true);
                    if (!is_array($data)) {
                        continue;
                    }
                    $label = null;
                    foreach ($this->used($def, $data, $usage, $seo) as $assetId) {
                        if (isset($usage[$assetId][$key])) {
                            continue;
                        }
                        $usage[$assetId][$key] = new AssetUsageEntry(
                            $label ??= $labeler->label(DynamicRecord::fromArray($def, $data)),
                            $this->editUrl($listing, (string) $row->get('record_id')),
                            self::NAME,
                            self::REVISION,
                            blocksDelete: false,
                            type: $listing->key,
                        );
                    }
                }
            }
        }

        return $usage;
    }

    /**
     * The asked-for assets this record's data uses: in its asset and blocks fields, or (with $seo) as its share image.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $asked asset id => anything
     * @return list<string>
     */
    private function used(TypeDefinition $def, array $data, array $asked, bool $seo): array
    {
        $found = $this->references->collect($def, $data);
        if ($seo) {
            $found[] = (string) SeoSection::fromValue($data[SeoSection::KEY] ?? null)->image();
        }

        return array_values(array_unique(array_filter($found, static fn (string $id): bool => isset($asked[$id]))));
    }

    /**
     * The types whose records can have a share image: those with a frontend route and the
     * site singleton (where the editor shows "SEO & sharing").
     *
     * @return array<string, true>
     */
    private function sharedTypes(): array
    {
        $types = array_fill_keys(array_map(
            static fn ($route): string => $route->type,
            FrontendResolver::routesFromCatalog($this->catalog),
        ), true);
        if ($this->catalog->get('site')?->singleton === true) {
            $types['site'] = true;
        }

        return $types;
    }

    private function editUrl(TypeListing $listing, string $id): string
    {
        return $listing->singleton
            ? $this->prefix . '/' . $listing->key
            : $this->prefix . '/' . $listing->key . '/' . rawurlencode($id) . '/edit';
    }

    /** @return list<string> the fields AssetReferences::collect() reads */
    private static function referenceFields(TypeDefinition $def): array
    {
        return array_keys(array_filter(
            $def->fields,
            static fn (TypeFieldDefinition $f): bool => $f->type === 'asset' || $f->type === 'blocks',
        ));
    }

    /**
     * The rows that may reference one of the assets. On SQL storage those whose asset or
     * blocks columns (and `_seo` with $seo) contain one of the ids (a reference always holds it
     * verbatim, in a list or block JSON too); elsewhere every row. A table without a
     * `_seo` column (not migrated yet) holds no share image; one that cannot be
     * filtered otherwise (a column missing) is read whole, as before.
     *
     * @param list<string> $columns
     * @param list<string> $assetIds
     * @return list<DynamicRecord>
     */
    private function candidates(TypeDefinition $def, array $columns, array $assetIds, bool $seo = false): array
    {
        $driver = $this->dm->driver($def->storage);
        if (!$driver instanceof PdoDriver || $assetIds === []) {
            return $this->dm->queryIn($def)->get()->items();
        }
        if ($seo) {
            try {
                return $this->matching($driver, $def, [...$columns, SeoSection::KEY], $assetIds);
            } catch (\PDOException) {
                if ($columns === []) {
                    return [];
                }
            }
        }
        try {
            return $this->matching($driver, $def, $columns, $assetIds);
        } catch (\PDOException) {
            return $this->dm->queryIn($def)->get()->items();
        }
    }

    /**
     * The rows whose $columns contain one of the ids (LIKE), each row once. The ids go in
     * chunks, so a page of assets stays within every database's bound-parameter limit.
     *
     * @param list<string> $columns
     * @param list<string> $assetIds
     * @return list<DynamicRecord>
     * @throws \PDOException when a column is missing
     */
    private function matching(PdoDriver $driver, TypeDefinition $def, array $columns, array $assetIds): array
    {
        $dialect = $driver->dialect();
        $like = static fn (string $column): string => $dialect->quoteIdentifier($column) . " LIKE ? ESCAPE '!'";
        $rows = [];
        foreach (array_chunk($assetIds, max(1, intdiv(500, max(1, count($columns))))) as $chunk) {
            $conditions = [];
            $params = [];
            foreach ($chunk as $assetId) {
                $pattern = '%' . strtr($assetId, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
                foreach ($columns as $column) {
                    $conditions[] = $like($column);
                    $params[] = $pattern;
                }
            }
            foreach ($driver->rawQuery('SELECT * FROM ' . $dialect->quoteIdentifier($def->table) . ' WHERE ' . implode(' OR ', $conditions), $params) as $row) {
                $rows[(string) ($row[$def->idField] ?? serialize($row))] = $row; // a row found by two chunks is walked once
            }
        }

        return array_values(array_map(static fn (array $row): DynamicRecord => DynamicRecord::fromArray($def, $row), $rows));
    }
}
