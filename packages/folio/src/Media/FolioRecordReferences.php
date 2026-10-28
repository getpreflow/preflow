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
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Content\TypeListing;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Media\Usage\AssetReferenceSource;
use Preflow\Media\Usage\AssetUsageEntry;

/**
 * Folio's records as a usage source: working copies of every type, published
 * snapshots (a live page may still show an asset the draft already dropped,
 * note "published version") and earlier revisions (restoring one brings the
 * asset back, note "earlier revision"). Each record is listed once. A revision
 * does not block a delete: the asset goes into the bin, and cleanup and
 * emptying the bin keep it while a revision needs it. Poster use is the media
 * core's own source (PosterReferences).
 *
 * Runs on every delete, so it reads only what can hold a reference: types
 * without an asset or blocks field are skipped, and on SQL storage only rows
 * whose asset or blocks columns (a revision's "data") contain the id (LIKE)
 * are walked.
 */
final class FolioRecordReferences implements AssetReferenceSource
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
        $usage = [];
        $labeler = new RecordLabeler();
        foreach ($this->catalog->all() as $listing) {
            $def = $this->registry->get($listing->key);
            $columns = self::referenceFields($def);
            if ($columns === []) {
                continue; // AssetReferences reads only asset and blocks fields
            }
            $sources = ['working' => $def];
            if ($listing->publishing) {
                $sources['published'] = ShadowTypes::published($def);
            }
            foreach ($sources as $where => $source) {
                foreach ($this->candidates($source, $columns, $assetId) as $record) {
                    $key = $listing->key . '/' . $record->getId();
                    if (isset($usage[$key]) || !in_array($assetId, $this->references->collect($def, $record->toArray()), true)) {
                        continue;
                    }
                    $usage[$key] = new AssetUsageEntry(
                        $labeler->label($record),
                        $this->editUrl($listing, (string) $record->getId()),
                        self::NAME,
                        $where === 'published' ? self::PUBLISHED : null,
                    );
                }
            }
            if ($listing->publishing) {
                foreach ($this->candidates(ShadowTypes::revisions($def), ['data'], $assetId) as $row) {
                    $key = $listing->key . '/' . (string) $row->get('record_id');
                    $data = json_decode((string) $row->get('data'), true);
                    if (isset($usage[$key]) || !is_array($data) || !in_array($assetId, $this->references->collect($def, $data), true)) {
                        continue;
                    }
                    $record = DynamicRecord::fromArray($def, $data);
                    $usage[$key] = new AssetUsageEntry(
                        $labeler->label($record),
                        $this->editUrl($listing, (string) $row->get('record_id')),
                        self::NAME,
                        self::REVISION,
                        blocksDelete: false,
                    );
                }
            }
        }

        return array_values($usage);
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
     * The rows that may reference the asset. On SQL storage those whose asset or
     * blocks columns contain the id (a reference always holds it verbatim, in a
     * list or block JSON too); elsewhere every row. A table that cannot be
     * filtered that way (a column missing) is read whole, as before.
     *
     * @param list<string> $columns
     * @return list<DynamicRecord>
     */
    private function candidates(TypeDefinition $def, array $columns, string $assetId): array
    {
        $driver = $this->dm->driver($def->storage);
        if (!$driver instanceof PdoDriver || $assetId === '') {
            return $this->dm->queryIn($def)->get()->items();
        }
        $dialect = $driver->dialect();
        $like = static fn (string $column): string => $dialect->quoteIdentifier($column) . " LIKE ? ESCAPE '!'";
        $pattern = '%' . strtr($assetId, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        try {
            $rows = $driver->rawQuery(
                'SELECT * FROM ' . $dialect->quoteIdentifier($def->table) . ' WHERE ' . implode(' OR ', array_map($like, $columns)),
                array_fill(0, count($columns), $pattern),
            );
        } catch (\PDOException) {
            return $this->dm->queryIn($def)->get()->items();
        }

        return array_map(static fn (array $row): DynamicRecord => DynamicRecord::fromArray($def, $row), $rows);
    }
}
