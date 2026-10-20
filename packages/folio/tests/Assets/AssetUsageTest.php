<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Assets\AssetUsage;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;
use Preflow\Folio\Tests\Support\AssetFixtures;

#[RequiresPhpExtension('gd')]
final class AssetUsageTest extends TestCase
{
    use AssetFixtures;

    private DataManager $dm;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        mkdir($this->assetRoot . '/models', 0777, true);
        file_put_contents($this->assetRoot . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'frontend' => ['route' => '/{slug}'],
            'fields' => [
                'title' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'cover' => ['type' => 'asset'],
                'gallery' => ['type' => 'asset', 'asset' => ['multiple' => true]],
                'sections' => ['type' => 'blocks'],
            ],
        ]));
        $this->registry = new TypeRegistry($this->assetRoot . '/models');
        $this->dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->assetRoot . '/store')], defaultDriver: 'json', typeRegistry: $this->registry);
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    public function test_collects_top_level_and_nested_block_assets(): void
    {
        $this->assertSame(['c1', 'g1', 'g2', 'n1'], $this->references()->collect($this->registry->get('page'), $this->pageData()));
    }

    public function test_map_rewrites_lists_and_nested_values(): void
    {
        $mapped = $this->references()->map($this->registry->get('page'), $this->pageData(), static fn (string $v): string => strtoupper($v));

        $this->assertSame('C1', $mapped['cover']);
        $this->assertSame('["G1","G2"]', $mapped['gallery']);
        $this->assertSame('N1', $mapped['sections'][0]['tradeoff'][0]['cover']);
        $this->assertSame('Hello', $mapped['sections'][0]['headline']['en']);
        $this->assertSame(['_uid' => 'ffffffffffffffff', '_block' => 'gone', 'cover' => 'kept'], $mapped['sections'][1]);
    }

    public function test_usage_includes_published_snapshots(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $def = $this->registry->get('page');
        $this->dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'cover' => '']), false);
        $this->dm->saveType(DynamicRecord::fromArray(ShadowTypes::published($def), ['uuid' => 'p1', 'title' => 'About', 'cover' => $asset->id]), false);

        $usage = $this->usage($library)->of($asset->id);

        $this->assertCount(1, $usage);
        $this->assertSame(['type' => 'page', 'id' => 'p1', 'label' => 'About', 'url' => '/folio/page/p1/edit', 'where' => 'published'], $usage[0]);
    }

    public function test_usage_counts_a_record_once(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $def = $this->registry->get('page');
        foreach ([$def, ShadowTypes::published($def)] as $d) {
            $this->dm->saveType(DynamicRecord::fromArray($d, ['uuid' => 'p1', 'title' => 'About', 'cover' => $asset->id]), false);
        }

        $usage = $this->usage($library)->of($asset->id);

        $this->assertCount(1, $usage);
        $this->assertSame('working', $usage[0]['where']);
    }

    public function test_poster_usage(): void
    {
        $library = $this->library();
        $poster = $library->upload($this->upload($this->png(10, 10), 'poster.png'), null);
        $video = $library->upload($this->upload("notes\n", 'clip.txt'), null);
        $library->update($video->id, ['poster' => $poster->id]);

        $usage = $this->usage($library)->of($poster->id);

        $this->assertSame([['type' => '_asset', 'id' => $video->id, 'label' => 'clip.txt', 'url' => '/folio/assets/' . $video->id, 'where' => 'poster']], $usage);
    }

    /** @return array<string, mixed> */
    private function pageData(): array
    {
        return [
            'uuid' => 'p1',
            'title' => 'T',
            'cover' => 'c1',
            'gallery' => '["g1","g2"]',
            'sections' => [
                ['_uid' => 'aaaaaaaaaaaaaaaa', '_block' => 'hero', 'headline' => ['en' => 'Hello'], 'tradeoff' => [
                    ['_uid' => 'bbbbbbbbbbbbbbbb', '_block' => 'tradeoff_note', 'chose' => ['en' => 'x'], 'cover' => 'n1'],
                ]],
                ['_uid' => 'ffffffffffffffff', '_block' => 'gone', 'cover' => 'kept'],
            ],
        ];
    }

    private function references(): AssetReferences
    {
        return new AssetReferences(new BlockRegistry([HeroBlock::class, LinkBlock::class, TradeoffNoteBlock::class]));
    }

    private function usage(\Preflow\Folio\Assets\AssetLibrary $library): AssetUsage
    {
        return new AssetUsage($this->references(), $this->dm, new TypeCatalog($this->assetRoot . '/models'), $this->registry, $library, '/folio');
    }
}
