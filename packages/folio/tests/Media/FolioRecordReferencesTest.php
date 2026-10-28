<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Media;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Assets\AssetReferences;
use Preflow\Folio\Media\FolioRecordReferences;
use Preflow\Media\Usage\AssetInUseException;
use Preflow\Media\Usage\AssetUsage;
use Preflow\Media\Usage\AssetUsageEntry;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\ShadowTypes;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;
use Preflow\Folio\Tests\Support\AssetFixtures;

#[RequiresPhpExtension('gd')]
final class FolioRecordReferencesTest extends TestCase
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

    public function test_collects_ids_in_a_json_locale_map_string(): void
    {
        $data = ['cover' => '{"de":"d1","fr":"f1"}', 'gallery' => '{"de":["g1","g2"],"fr":"g3"}'];

        $this->assertSame(['d1', 'f1', 'g1', 'g2', 'g3'], $this->references()->collect($this->registry->get('page'), $data));
    }

    public function test_map_rewrites_a_json_locale_map_and_keeps_the_object_shape(): void
    {
        $data = ['cover' => '{"de":"d1","fr":"f1"}', 'gallery' => '{"de":["g1","g2"],"fr":"g/3"}', 'title' => '{"x":"y"}'];

        $mapped = $this->references()->map($this->registry->get('page'), $data, static fn (string $v): string => strtoupper($v));

        $this->assertSame('{"de":"D1","fr":"F1"}', $mapped['cover']);
        $this->assertSame('{"de":["G1","G2"],"fr":"G/3"}', $mapped['gallery']);
        $this->assertSame('{"x":"y"}', $mapped['title']); // not an asset field
    }

    public function test_empty_and_invalid_json_maps_stay_as_they_are(): void
    {
        $def = $this->registry->get('page');
        $mapped = $this->references()->map($def, ['cover' => '{}', 'gallery' => '{not json'], static fn (string $v): string => 'X');

        $this->assertSame('{}', $mapped['cover']);
        $this->assertSame('{not json', $mapped['gallery']); // invalid JSON stays untouched
    }

    public function test_usage_includes_published_snapshots(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $def = $this->registry->get('page');
        $this->dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'cover' => '']), false);
        $this->dm->saveType(DynamicRecord::fromArray(ShadowTypes::published($def), ['uuid' => 'p1', 'title' => 'About', 'cover' => $asset->id]), false);

        $usage = $this->usage()->usagesOf($asset->id);

        $this->assertEquals([new AssetUsageEntry('About', '/folio/page/p1/edit', 'folio', 'published version')], $usage);
    }

    public function test_asset_used_only_in_a_published_version_cannot_be_deleted(): void
    {
        $usage = new AssetUsage();
        $library = $this->library(usage: $usage);
        $usage->register($this->usage());
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $def = $this->registry->get('page');
        $this->dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'cover' => '']), false);
        $this->dm->saveType(DynamicRecord::fromArray(ShadowTypes::published($def), ['uuid' => 'p1', 'title' => 'About', 'cover' => $asset->id]), false);

        try {
            $library->delete($asset->id);
            $this->fail('delete() should refuse an asset a published version uses');
        } catch (AssetInUseException $e) {
            $this->assertEquals([new AssetUsageEntry('About', '/folio/page/p1/edit', 'folio', 'published version')], $e->usages);
        }
        $this->assertNotNull($library->find($asset->id));
    }

    public function test_usage_counts_a_record_once(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $def = $this->registry->get('page');
        foreach ([$def, ShadowTypes::published($def)] as $d) {
            $this->dm->saveType(DynamicRecord::fromArray($d, ['uuid' => 'p1', 'title' => 'About', 'cover' => $asset->id]), false);
        }

        $usage = $this->usage()->usagesOf($asset->id);

        $this->assertCount(1, $usage);
        $this->assertNull($usage[0]->note); // the working copy
    }

    public function test_revision_reference_is_reported_non_blocking(): void
    {
        $library = $this->library();
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $publisher = $this->publisher();
        $def = $this->registry->get('page');
        $publisher->publish(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', 'cover' => $asset->id]), null);
        $publisher->publish(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About us', 'slug' => 'about', 'cover' => '']), null);

        $usage = $this->usage()->usagesOf($asset->id);

        $this->assertEquals([new AssetUsageEntry('About', '/folio/page/p1/edit', 'folio', 'earlier revision', blocksDelete: false)], $usage);
        $this->assertFalse($usage[0]->blocksDelete);
    }

    public function test_revision_reference_lets_a_delete_bin_the_asset(): void
    {
        $usage = new AssetUsage();
        $library = $this->library(['bin_dir' => $this->assetRoot . '/bin'], usage: $usage);
        $usage->register($this->usage());
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $publisher = $this->publisher();
        $def = $this->registry->get('page');
        $publisher->publish(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', 'cover' => $asset->id]), null);
        $publisher->publish(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', 'cover' => '']), null);

        $library->delete($asset->id);

        $this->assertNull($library->find($asset->id));
        $this->assertNotNull($library->findBinned($asset->id));
    }

    public function test_working_reference_still_blocks(): void
    {
        $usage = new AssetUsage();
        $library = $this->library(['bin_dir' => $this->assetRoot . '/bin'], usage: $usage);
        $usage->register($this->usage());
        $asset = $library->upload($this->upload($this->png(10, 10), 'a.png'), null);
        $publisher = $this->publisher();
        $def = $this->registry->get('page');
        $publisher->publish(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', 'cover' => $asset->id]), null);
        $publisher->publish(DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => 'About us', 'slug' => 'about', 'cover' => $asset->id]), null);

        // The working copy wins over its revisions: one entry, blocking.
        $this->assertEquals([new AssetUsageEntry('About us', '/folio/page/p1/edit', 'folio')], $this->usage()->usagesOf($asset->id));
        try {
            $library->delete($asset->id);
            $this->fail('delete() should refuse an asset a working copy uses');
        } catch (AssetInUseException $e) {
            $this->assertCount(1, $e->usages);
        }
        $this->assertNotNull($library->find($asset->id));
    }

    public function test_poster_usage(): void
    {
        $library = $this->library();
        $poster = $library->upload($this->upload($this->png(10, 10), 'poster.png'), null);
        $video = $library->upload($this->upload("notes\n", 'clip.txt'), null);
        $library->update($video->id, ['poster' => $poster->id]);

        // Poster use is the media core's own source (PosterReferences); Folio's records do not report it twice.
        $this->assertSame([], $this->usage()->usagesOf($poster->id));
        $core = new \Preflow\Media\Usage\PosterReferences(static fn () => $library, static fn ($a) => '/folio/assets/' . $a->id);
        $this->assertEquals([new AssetUsageEntry('clip.txt', '/folio/assets/' . $video->id, 'media', 'as poster')], $core->usagesOf($poster->id));
    }

    public function test_types_without_asset_or_blocks_fields_are_not_read(): void
    {
        file_put_contents($this->assetRoot . '/models/tag.json', json_encode([
            'key' => 'tag', 'table' => 'tag', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Tags',
            'fields' => ['title' => ['type' => 'string'], 'note' => ['type' => 'text']],
        ]));
        $registry = new TypeRegistry($this->assetRoot . '/models');
        $json = new JsonFileDriver($this->assetRoot . '/store');
        $reads = [];
        $counting = new class ($json, $reads) implements \Preflow\Data\StorageDriver {
            /** @param list<string> $reads */
            public function __construct(private readonly JsonFileDriver $inner, private array &$reads) {}
            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { $this->reads[] = $type; return $this->inner->findOne($type, $id, $idField); }
            public function findMany(string $type, \Preflow\Data\Query $query): \Preflow\Data\ResultSet { $this->reads[] = $type; return $this->inner->findMany($type, $query); }
            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }
            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }
            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }
            public function lastInsertId(): string|int { return $this->inner->lastInsertId(); }
            public function rawQuery(string $sql, array $bindings = []): array { return $this->inner->rawQuery($sql, $bindings); }
        };
        $dm = new DataManager(drivers: ['json' => $counting], defaultDriver: 'json', typeRegistry: $registry);
        $dm->saveType(DynamicRecord::fromArray($registry->get('page'), ['uuid' => 'p1', 'title' => 'About', 'cover' => 'a1']), false);
        $dm->saveType(DynamicRecord::fromArray($registry->get('tag'), ['uuid' => 't1', 'title' => 'a1']), false);

        $usage = (new FolioRecordReferences($this->references(), $dm, new TypeCatalog($this->assetRoot . '/models'), $registry, '/folio'))->usagesOf('a1');

        $this->assertEquals([new AssetUsageEntry('About', '/folio/page/p1/edit', 'folio')], $usage);
        $this->assertContains('page', $reads);
        $this->assertNotContains('tag', $reads);
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

    private function publisher(): Publisher
    {
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());

        return new Publisher($this->dm, new TypeCatalog($this->assetRoot . '/models'), new RecordValidator($fieldTypes, $this->dm));
    }

    private function usage(): FolioRecordReferences
    {
        return new FolioRecordReferences($this->references(), $this->dm, new TypeCatalog($this->assetRoot . '/models'), $this->registry, '/folio');
    }
}
