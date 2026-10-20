<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\ShadowTypes;

/**
 * Where an asset is used: working copies of every type, published snapshots
 * (a live page may still show an asset the draft already dropped) and assets
 * using it as a video poster. Each record is listed once.
 */
final class AssetUsage
{
    public function __construct(
        private readonly AssetReferences $references,
        private readonly DataManager $dm,
        private readonly TypeCatalog $catalog,
        private readonly TypeRegistry $registry,
        private readonly AssetLibrary $library,
        private readonly string $prefix,
    ) {}

    /** @return list<array{type: string, id: string, label: string, url: string, where: string}> */
    public function of(string $assetId): array
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
                    $usage[$key] = [
                        'type' => $listing->key,
                        'id' => (string) $record->getId(),
                        'label' => $labeler->label($record),
                        'url' => $listing->singleton
                            ? $this->prefix . '/' . $listing->key
                            : $this->prefix . '/' . $listing->key . '/' . rawurlencode((string) $record->getId()) . '/edit',
                        'where' => $where,
                    ];
                }
            }
        }

        foreach ($this->library->all() as $asset) {
            if ($asset->posterId === $assetId) {
                $usage['_asset/' . $asset->id] = [
                    'type' => '_asset',
                    'id' => $asset->id,
                    'label' => $asset->label(),
                    'url' => $this->prefix . '/assets/' . $asset->id,
                    'where' => 'poster',
                ];
            }
        }

        return array_values($usage);
    }
}
