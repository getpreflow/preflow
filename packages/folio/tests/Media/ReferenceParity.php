<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use Preflow\Blocks\BlockRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;
use Preflow\Media\Usage\AssetUsageEntry;

/**
 * One set of records that reference an asset in every place a usage can be (a list field, a
 * translatable list in a locale other than the default, nested blocks, the share image), read
 * by FolioRecordReferences on two storages: the usages must be the same.
 */
trait ReferenceParity
{
    /** The model of the type the scenario writes ("post", routed so it has a share image). */
    private static function postModel(string $storage): array
    {
        return [
            'key' => 'post', 'table' => 'post', 'storage' => $storage, 'id_field' => 'uuid', 'label' => 'Posts',
            'frontend' => ['route' => '/posts/{slug}'], 'publishing' => false,
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'gallery' => ['type' => 'asset', 'asset' => ['multiple' => true]],
                'photos' => ['type' => 'asset', 'translatable' => true, 'asset' => ['multiple' => true]],
                'sections' => ['type' => 'blocks'],
            ],
        ];
    }

    private static function seedPosts(DataManager $dm, TypeDefinition $post, string $id, string $other): void
    {
        $rows = [
            'r1' => ['title' => 'List', 'slug' => 'list', 'gallery' => [$other, $id]],
            'r2' => ['title' => 'Blocks', 'slug' => 'blocks', 'sections' => [
                ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => ['en' => 'Hi'], 'tradeoff' => [
                    ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'tradeoff_note', 'chose' => ['en' => 'x'], 'cover' => $id],
                ]],
            ]],
            'r3' => ['title' => 'Shared', 'slug' => 'shared', '_seo' => ['image' => $id]],
            'r4' => ['title' => 'German photos', 'slug' => 'photos', 'photos' => ['de' => [$id]]],
            'r5' => ['title' => $id, 'slug' => 'named', 'gallery' => [$other]], // the id, but in no asset place
        ];
        foreach ($rows as $uuid => $data) {
            $dm->saveType(DynamicRecord::fromArray($post, ['uuid' => $uuid] + $data), false);
        }
    }

    /** @return list<AssetUsageEntry> sorted by url */
    private static function postUsages(DataManager $dm, TypeRegistry $registry, string $models, string $id): array
    {
        $references = new FolioRecordReferences(
            new AssetReferences(new BlockRegistry([HeroBlock::class, LinkBlock::class, TradeoffNoteBlock::class])),
            $dm,
            new TypeCatalog($models),
            $registry,
            '/folio',
        );
        $usages = $references->usagesOf($id);
        usort($usages, static fn (AssetUsageEntry $a, AssetUsageEntry $b): int => strcmp($a->url, $b->url));

        return $usages;
    }

    /** @return list<AssetUsageEntry> */
    private static function expectedPostUsages(): array
    {
        return [
            new AssetUsageEntry('List', '/folio/post/r1/edit', 'folio', type: 'post'),
            new AssetUsageEntry('Blocks', '/folio/post/r2/edit', 'folio', type: 'post'),
            new AssetUsageEntry('Shared', '/folio/post/r3/edit', 'folio', type: 'post'),
            new AssetUsageEntry('German photos', '/folio/post/r4/edit', 'folio', type: 'post'),
        ];
    }
}
