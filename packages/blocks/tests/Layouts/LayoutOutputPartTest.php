<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Records\ListsRecordReferences;
use Preflow\Data\Records\OutputPart;
use Preflow\Data\Records\ReadOptions;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\TypeRegistry;

/**
 * A page's layout and areas as the blocks package's part of the one read output (0.93):
 * LayoutResource is an OutputPart registered on RecordParts, so RecordOutput::toArray() of a type
 * with layouts adds "_layout", "_areas" and "_unplaced" (0.92's LayoutResource::toArray() output),
 * each block's fields through their values, and lists the records the areas' blocks reference.
 */
final class LayoutOutputPartTest extends BlocksAppTestCase
{
    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';
    private const string G = '9a9a9a9a9a9a9a9a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\BlocksServiceProvider::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\GridLayouts::class];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Values\\Card::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\Grid::class], 'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class]];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string', 'translatable' => true]],
            'layouts' => ['allowed' => ['standard', 'promo'], 'default' => 'standard'],
        ]));
        $this->write('config/models/note.json', (string) json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    public function test_layout_keys_match_layout_resource_0_92(): void
    {
        $card = self::card(self::B, ['body' => ['en' => '<p>Hi</p>'], 'count' => 2, 'kind' => 'plain', 'url' => 'https://example.org/']);
        $this->store(['uuid' => 'p1', 'title' => ['en' => 'About'], '_layout' => 'standard', '_areas' => ['main' => [$card], 'hero' => [self::card(self::A)]]]);
        $resource = $this->resource();
        $record = $this->page('p1');

        $this->assertInstanceOf(OutputPart::class, $resource);
        $this->assertSame(['_layout', '_areas', '_unplaced'], $resource->keys($this->types()->get('page')));
        $this->assertSame([], $resource->keys($this->types()->get('note')), 'a type without layouts gets none of the keys');

        $read = $resource->read($record, new ReadOptions());
        $this->assertSame(json_encode($resource->toArray($record)), json_encode($read), 'the 0.92 output, also its empty maps as objects');
        $this->assertSame(['_layout', '_areas', '_unplaced'], array_keys($read));
        $this->assertSame(['key' => 'standard', 'label' => 'Standard', 'pinned' => false, 'fallback' => false, 'stored' => 'standard'], $read['_layout']);
        $this->assertNull($read['_areas']['main'][0]['url'], 'a field its show_when hides is null');
        $this->assertSame([self::A], array_column($read['_unplaced']['hero'], '_uid'));
        $this->assertSame(json_encode($read), json_encode($resource->read($record, new ReadOptions(locale: 'de'))), 'blocks keep their locale maps (shape "area_map")');
    }

    public function test_record_output_adds_the_layout_keys(): void
    {
        $this->store(['uuid' => 'p1', 'title' => ['en' => 'About', 'de' => 'Über uns'], '_layout' => 'standard', '_areas' => ['main' => [self::card(self::B)]]]);
        $output = $this->app()->container()->get(RecordOutput::class);

        $page = $output->toArray($this->page('p1'));
        $this->assertSame(['_id', 'title', '_layout', '_areas', '_unplaced'], array_keys($page));
        $this->assertSame([self::B], array_column($page['_areas']['main'], '_uid'));
        $this->assertStringContainsString('"_unplaced":{}', (string) json_encode($page), 'an empty map is an object');

        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('note'), ['uuid' => 'n1', 'title' => 'Note']), validate: false);
        $note = $output->toArray($this->data()->findType('note', 'n1') ?? self::fail('no note'));
        $this->assertSame(['_id' => 'n1', 'title' => 'Note'], $note, 'a type without layouts: no layout keys');

        $this->assertContains($this->resource(), $this->app()->container()->get(RecordParts::class)->outputs(), 'registered by BlocksServiceProvider');
    }

    public function test_area_blocks_read_through_their_values(): void
    {
        // An import straight to the driver: numeric text and unclean rich text inside an area's block.
        $this->data()->driver('json')->save('page', 'p1', ['uuid' => 'p1', '_layout' => 'standard', '_areas' => ['main' => [
            self::card(self::B, ['count' => '3', 'note' => '<p>Hi</p><script>alert(1)</script>', 'kind' => 'plain']),
        ]]]);

        $block = $this->app()->container()->get(RecordOutput::class)->toArray($this->page('p1'))['_areas']['main'][0];

        $this->assertSame(3, $block['count'], 'a number stored as text reads as a number, as in a blocks field');
        $this->assertSame('<p>Hi</p>', $block['note'], 'rich text is cleaned again');
    }

    public function test_area_blocks_references_are_listed(): void
    {
        $grid = ['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => '1-1', 'areas' => ['c1' => [self::card(self::A, ['images' => ['i2']])]]]];
        $this->store(['uuid' => 'p1', '_layout' => 'standard', '_areas' => ['main' => [self::card(self::B, ['images' => ['i1'], 'author' => 'u1']), $grid], 'hero' => [self::card(self::A, ['images' => ['i3']])]]]);

        $this->assertInstanceOf(ListsRecordReferences::class, $this->resource());
        $references = $this->app()->container()->get(RecordOutput::class)->references($this->page('p1'));

        $this->assertSame(['i1', 'i2', 'i3'], $references['asset'], 'every stored area, its blocks and their columns');
        $this->assertSame(['u1'], $references['person']);
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function card(string $uid, array $values = []): array
    {
        return ['_uid' => $uid, '_block' => 'value_card', ...$values];
    }

    private function resource(): LayoutResource
    {
        return $this->app()->container()->get(LayoutResource::class);
    }

    private function page(string $id): DynamicRecord
    {
        return $this->data()->findType('page', $id) ?? self::fail("no page {$id}");
    }

    /** @param array<string, mixed> $values a page stored without validation */
    private function store(array $values): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), $values), validate: false);
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }
}
