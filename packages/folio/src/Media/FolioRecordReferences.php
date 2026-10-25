<?php

declare(strict_types=1);

namespace Preflow\Folio\Media;

use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Media\Usage\AssetReferenceSource;
use Preflow\Media\Usage\AssetUsageEntry;

/**
 * Folio's records as a usage source: working copies of every type, published
 * snapshots (a live page may still show an asset the draft already dropped,
 * note "published version"). Each record is listed once. Poster use is the
 * media core's own source (PosterReferences).
 */
final class FolioRecordReferences implements AssetReferenceSource
{
    public const string NAME = 'folio';
    public const string PUBLISHED = 'published version';

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
            $sources = ['working' => $def];
            if ($listing->publishing) {
                $sources['published'] = ShadowTypes::published($def);
            }
            foreach ($sources as $where => $source) {
                foreach ($this->dm->queryIn($source)->get()->items() as $record) {
                    $key = $listing->key . '/' . $record->getId();
                    if (isset($usage[$key]) || !in_array($assetId, $this->references->collect($def, $record->toArray()), true)) {
                        continue;
                    }
                    $usage[$key] = new AssetUsageEntry(
                        $labeler->label($record),
                        $listing->singleton
                            ? $this->prefix . '/' . $listing->key
                            : $this->prefix . '/' . $listing->key . '/' . rawurlencode((string) $record->getId()) . '/edit',
                        self::NAME,
                        $where === 'published' ? self::PUBLISHED : null,
                    );
                }
            }
        }

        return array_values($usage);
    }
}
