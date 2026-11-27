<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Blocks\BlockConfigException;
use Preflow\Blocks\BlockLists;
use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Describe\ContentDescription;
use Preflow\Blocks\Layouts\AreaLayoutSets;
use Preflow\Blocks\Layouts\AreasValue;
use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\LayoutReport;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\TestResponse;
use Preflow\Validation\ValidationException;

/**
 * The areas field (0.92): a layout from a named area layout set and the blocks of each area, as
 * the columns block holds its columns, without Folio. The set "grid" is registered by the fixture
 * provider GridLayouts; the block "grid" (Fixtures\Columns\Grid) has an areas field "columns", the
 * page type a blocks field "sections" and an areas field "columns" of its own.
 */
final class AreasFieldTest extends BlocksAppTestCase
{
    private const string G = '9a9a9a9a9a9a9a9a';
    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';
    private const string C = 'cccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $sqlite = $this->dir . '/storage/pages.sqlite';
        @mkdir(dirname($sqlite), 0777, true);
        $pdo = new \PDO('sqlite:' . $sqlite);
        $pdo->exec('CREATE TABLE spage (uuid TEXT PRIMARY KEY, title TEXT, slug TEXT, sections TEXT, columns TEXT)');
        $pdo = null;
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\BlocksServiceProvider::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\GridLayouts::class];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => [\n"
            . "    'json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],\n"
            . "    'sqlite' => ['driver' => 'sqlite', 'path' => " . var_export($sqlite, true) . "],\n"
            . "], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->blocks();
        foreach (['page' => 'json', 'spage' => 'sqlite'] as $key => $storage) {
            $this->write('config/models/' . $key . '.json', (string) json_encode([
                'key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid', 'titleField' => 'title',
                'fields' => [
                    'title' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'sections' => ['type' => 'blocks'],
                    'columns' => ['type' => 'areas', 'layouts' => 'grid'],
                ],
            ]));
        }
    }

    /** @param list<class-string> $more */
    private function blocks(array $more = []): void
    {
        $classes = array_map(static fn (string $class): string => '\\' . $class . '::class', [
            \Preflow\Blocks\Tests\Fixtures\Checks\Section::class,
            \Preflow\Blocks\Tests\Fixtures\Checks\SectionQuote::class,
            \Preflow\Blocks\Tests\Fixtures\Columns\Grid::class,
            ...$more,
        ]);
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [" . implode(', ', $classes) . "]];\n");
    }

    public function test_areas_field_without_a_registered_set_is_refused_at_registry_build(): void
    {
        $this->blocks([\Preflow\Blocks\Tests\Fixtures\Columns\Unregistered::class]);

        try {
            $this->app()->container()->get(BlockRegistry::class);
            $this->fail('a block whose areas field names no registered set was taken');
        } catch (BlockConfigException $e) {
            $this->assertSame('Block "stray_grid": field "cells": No package registers the area layout set "missing". The package that brings the block registers it in its service provider (config/providers.php).', $e->getMessage());
        }
    }

    public function test_a_select_cannot_take_its_options_from_an_areas_field(): void
    {
        $this->blocks([\Preflow\Blocks\Tests\Fixtures\Columns\OptionsFromColumns::class]);

        try {
            $this->app()->container()->get(BlockRegistry::class);
            $this->fail('a select taking its options from an areas field was taken');
        } catch (BlockConfigException $e) {
            $this->assertSame('Block "picky_grid": field "pick": Select field option "options_from" names "columns", an areas field; name a field of this block that holds values (such as tags).', $e->getMessage());
        }
    }

    public function test_a_class_backed_type_declares_its_areas_field_and_layouts(): void
    {
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models', 'model_paths' => [" . var_export(dirname(__DIR__) . '/Fixtures/Models', true) . "]];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\Section::class], 'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class]];\n");
        // The admin file adds what is the type's own, not a field's: its page layouts.
        $this->write('config/models/board.json', (string) json_encode(['key' => 'board', 'label' => 'Boards', 'layouts' => ['allowed' => ['standard'], 'default' => 'standard']]));

        $layouts = $this->app()->container()->get(PageLayouts::class)->forType('board');
        $this->assertSame(['standard'], $layouts?->allowed, 'a class-backed type opts into layouts like a model file');
        $field = $this->types()->get('board')->fields['columns'];
        $this->assertSame('grid', BlockLists::setName($field), 'the class declares its areas field\'s set');

        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('board'), ['uuid' => 'b1', 'title' => 'Board', 'slug' => 'board', 'columns' => ['layout' => 'full', 'areas' => ['c1' => [self::section(self::A)]]], '_layout' => 'standard', '_areas' => ['main' => []]]));
        $stored = $this->fresh()->container()->get(DataManager::class)->findType('board', 'b1');
        $this->assertSame('standard', $stored?->get('_layout'));
        $this->assertSame([self::A], array_column(AreasValue::fromStored($stored?->get('columns'))->areas['c1'], '_uid'));
    }

    public function test_areas_field_of_a_type_without_a_set_is_refused_at_boot(): void
    {
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'columns' => ['type' => 'areas']],
        ]));

        try {
            $this->app();
            $this->fail('a type whose areas field names no set booted');
        } catch (LayoutConfigException $e) {
            $this->assertSame('Type "page": field "columns": An areas field takes its layouts from a named area layout set, and "layouts" names none. Give it the name of one, such as "layouts": "columns".', $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['JSON' => ['page'], 'SQLite' => ['spage']];
    }

    #[DataProvider('storages')]
    public function test_areas_value_round_trips_on_every_storage(string $type): void
    {
        $columns = ['layout' => '2-1', 'areas' => [
            'c1' => [self::section(self::A, ['title' => ['en' => 'Left "quoted"', 'de' => 'Links']])],
            'c2' => [],
        ]];
        $sections = [['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => 'full', 'areas' => ['c1' => [self::section(self::B)]]]]];
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), ['uuid' => 'p1', 'title' => 'About', 'columns' => $columns, 'sections' => $sections]));

        $record = $this->fresh()->container()->get(DataManager::class)->findType($type, 'p1');
        $this->assertSame($columns, AreasValue::fromStored($record?->get('columns'))->toStored(), 'the field\'s value, as saved (an array or JSON text of it)');
        $nested = BlockLists::of($this->types()->get($type)->fields['sections'], $record?->get('sections'))[''][0]['columns'];
        $this->assertSame(['layout' => 'full', 'areas' => ['c1' => [self::section(self::B)]]], AreasValue::fromStored($nested)->toStored(), 'inside a block too');
        $this->assertSame('{"layout":"2-1","areas":{"c1":[],"c2":[]}}', json_encode(AreasValue::of('2-1', ['c1' => [], 'c2' => []])->toStored()), 'the areas stay a map in JSON');
    }

    public function test_unknown_column_layout_resolves_by_column_count(): void
    {
        $set = $this->app()->container()->get(AreaLayoutSets::class)->get('grid');

        $three = AreasValue::fromStored(['layout' => 'gone', 'areas' => ['c1' => [self::section(self::A)], 'c2' => [], 'c3' => [self::section(self::B)]]]);
        $this->assertSame('1-1-1', $three->resolve($set)?->key, 'the set\'s first layout with as many columns as are stored');
        $this->assertTrue($three->fallback($set));
        $this->assertSame(['c1', 'c2', 'c3'], array_keys($three->placement($set)->placed()));

        $five = AreasValue::fromStored((string) json_encode(['layout' => 'gone', 'areas' => ['c1' => [], 'c2' => [], 'c3' => [], 'c4' => [], 'c5' => [self::section(self::C)]]]));
        $this->assertSame('full', $five->resolve($set)?->key, 'none with five: the set\'s fallback');
        $this->assertSame(['c1'], array_keys($five->placement($set)->placed()));
        $this->assertSame(['c5'], array_keys($five->placement($set)->unplaced()), 'kept, not shown: the empty ones are not listed');
        $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5'], array_keys($five->placement($set)->areas()), 'nothing dropped');

        $kept = AreasValue::fromStored(['layout' => '1-1', 'areas' => ['c1' => []]]);
        $this->assertSame('1-1', $kept->resolve($set)?->key, 'a key the set has wins over the count');
        $this->assertFalse($kept->fallback($set));
        $this->assertSame(['c1' => [], 'c2' => []], $kept->placement($set)->placed(), 'a column with nothing stored is empty');
        $this->assertSame('full', AreasValue::fromStored(null)->resolve($set)?->key, 'an empty value: the fallback');
    }

    public function test_block_lists_of_blocks_and_areas_fields(): void
    {
        $blocks = new TypeFieldDefinition(name: 'sections', type: 'blocks');
        $areas = new TypeFieldDefinition(name: 'columns', type: 'areas', config: ['layouts' => 'grid']);
        $text = new TypeFieldDefinition(name: 'title', type: 'string');
        $value = ['layout' => '1-1', 'areas' => ['c1' => [self::section(self::A)], 'c2' => [], 'c3' => [self::section(self::B), 'not a block']]];

        $this->assertTrue(BlockLists::holds($blocks));
        $this->assertTrue(BlockLists::holds($areas));
        $this->assertFalse(BlockLists::holds($text));
        $this->assertSame(['' => [self::section(self::A)]], BlockLists::of($blocks, (string) json_encode([self::section(self::A)])));
        $this->assertSame(['areas.c1' => [self::section(self::A)], 'areas.c2' => [], 'areas.c3' => [self::section(self::B)]], BlockLists::of($areas, (string) json_encode($value)), 'every stored area, JSON text too');
        $sets = $this->app()->container()->get(AreaLayoutSets::class);
        $this->assertSame(['areas.c1', 'areas.c2'], array_keys(BlockLists::of($areas, $value, $sets)), 'with the sets: the areas the layout shows');
        $this->assertSame([], BlockLists::of($text, 'x'));

        $paths = [];
        $mapped = BlockLists::map($areas, $value, static function (array $list, string $path) use (&$paths): array {
            $paths[] = $path;

            return array_map(static fn (array $block): array => ['seen' => true] + $block, $list);
        });
        $this->assertSame(['areas.c1', 'areas.c2', 'areas.c3'], $paths);
        $this->assertSame('1-1', $mapped['layout'], 'the {layout, areas} shape is kept');
        $this->assertTrue($mapped['areas']['c3'][0]['seen'], 'an area the layout lacks is mapped too');
        $this->assertNull(BlockLists::map($areas, null, static fn (array $list): array => $list), 'an empty value comes back as given');
        $this->assertSame('x', BlockLists::map($text, 'x', static fn (array $list): array => []));
    }

    public function test_show_when_inside_a_column_hides_the_field(): void
    {
        $grid = ['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => '1-1', 'areas' => [
            'c1' => [self::section(self::A, ['kind' => 'text', 'button_url' => 'https://example.com/old'])],
            'c2' => [self::section(self::B, ['kind' => 'link', 'button_url' => 'https://example.com/shown'])],
        ]]];
        $registry = $this->app()->container()->get(BlockRegistry::class);

        $visible = $registry->visibleBlock($grid);
        $this->assertNull($visible['columns']['areas']['c1'][0]['button_url'], 'hidden by its own block\'s show_when');
        $this->assertSame('https://example.com/shown', $visible['columns']['areas']['c2'][0]['button_url']);

        $record = $registry->visibleBlocksIn(['columns' => $grid['columns'], 'sections' => [$grid]], $this->types()->get('page')->fields);
        $this->assertNull($record['columns']['areas']['c1'][0]['button_url'], 'a record\'s own areas field');
        $this->assertNull($record['sections'][0]['columns']['areas']['c1'][0]['button_url'], 'a column of a block in a blocks field');
    }

    public function test_required_field_of_a_block_in_a_column_is_refused_without_folio(): void
    {
        $grid = static fn (array $areas, string $layout = '1-1'): array => ['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => $layout, 'areas' => $areas]];
        $empty = self::section(self::A, ['title' => ['en' => '']]);

        $errors = $this->errorsOfSave(['uuid' => 'p1', 'sections' => [$grid(['c1' => [$empty], 'c2' => []])]]);
        $this->assertSame(['sections.' . self::G . '.columns.areas.c1.' . self::A . '.title' => ['This field is required.']], $errors, 'the key Folio\'s form shows');

        $own = $this->errorsOfSave(['uuid' => 'p1', 'columns' => ['layout' => 'full', 'areas' => ['c1' => [self::section(self::B, ['quotes' => [['_uid' => self::C, '_block' => 'section_quote', 'text' => '']]])]]]]);
        $this->assertSame(['columns.areas.c1.' . self::B . '.quotes.' . self::C . '.text'], array_keys($own), 'a record\'s own areas field, nested blocks too');

        $this->assertSame([], $this->errorsOfSave(['uuid' => 'p1', 'sections' => [$grid(['c1' => [], 'c2' => [], 'c3' => [$empty]])]]), 'a column the layout does not show is kept, not checked');
    }

    public function test_column_contents_are_checked_against_the_stored_value(): void
    {
        $grid = static fn (array $areas, string $layout = '2-1'): array => ['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => $layout, 'areas' => $areas]];
        $nested = self::nested(self::C);
        $prefix = 'sections.' . self::G . '.columns';

        // New: a grid in a column and two blocks in "c2" (at most one) are refused, keyed by the column.
        $errors = $this->errorsOfSave(['uuid' => 'p1', 'sections' => [$grid(['c1' => [$nested], 'c2' => [self::section(self::A), self::section(self::B)]])]]);
        $this->assertSame([$prefix . '.areas.c1', $prefix . '.areas.c2'], array_keys($errors));
        $this->assertSame('The area "c1" does not take "grid" blocks. Move it to another area or remove it.', $errors[$prefix . '.areas.c1'][0]);
        $this->assertSame('The area "c2" takes at most 1 blocks.', $errors[$prefix . '.areas.c2'][0]);
        $this->assertSame([$prefix . '.layout' => ['The layout "wide" is not one of the layouts of the set "grid" (full, 1-1, 2-1, 1-1-1). Choose one of them.']], $this->errorsOfSave(['uuid' => 'p1', 'sections' => [$grid(['c1' => []], 'wide')]]));

        // Stored so (written past the rules, as before a site narrowed its columns): it stays savable.
        $this->store(['uuid' => 'p1', 'title' => 'About', 'sections' => [$grid(['c1' => [$nested], 'c2' => [self::section(self::A), self::section(self::B)]])], 'columns' => ['layout' => 'gone', 'areas' => ['c1' => [$nested]]]]);
        $page = $this->data()->findType('page', 'p1');
        $page?->set('title', 'About us');
        $this->data()->saveType($page);
        $this->assertSame('About us', $this->data()->findType('page', 'p1')?->get('title'), 'a stored grid in a column, an over-full column and a stale key pass unchanged');

        // A write may reduce the over-full column, never add to it or add a grid.
        $this->assertSame([], $this->errorsOfSave(['uuid' => 'p1', 'sections' => [$grid(['c1' => [$nested], 'c2' => [self::section(self::A)]])]]));
        $more = $this->errorsOfSave(['uuid' => 'p1', 'sections' => [$grid(['c1' => [$nested, self::nested('dddddddddddddddd')], 'c2' => [self::section(self::A), self::section(self::B), self::section(self::C)]])]]);
        $this->assertSame([$prefix . '.areas.c1', $prefix . '.areas.c2'], array_keys($more));
        $new = $this->errorsOfSave(['uuid' => 'p1', 'sections' => [['_uid' => 'eeeeeeeeeeeeeeee'] + $grid(['c1' => [$nested]], '1-1')]]);
        $this->assertSame(['sections.eeeeeeeeeeeeeeee.columns.areas.c1'], array_keys($new), 'a new block is checked as new');
    }

    public function test_report_names_records_with_a_missing_column_layout(): void
    {
        $this->store(['uuid' => 'p1', 'slug' => 'about', 'title' => 'About', 'sections' => [['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => 'gone', 'areas' => ['c1' => []]]]]]);
        $this->store(['uuid' => 'p2', 'title' => 'Team', 'columns' => ['layout' => 'gone', 'areas' => ['c1' => []]]]);
        $this->store(['uuid' => 'p3', 'title' => 'Narrow', 'columns' => ['layout' => 'full', 'areas' => ['c1' => [], 'c2' => [self::section(self::A)]]]]);
        $this->store(['uuid' => 'p4', 'title' => 'Fine', 'columns' => ['layout' => '1-1', 'areas' => ['c1' => [], 'c2' => [self::section(self::A)]]]]);
        $container = $this->app()->container();

        $lines = (new LayoutReport($container->get(PageLayouts::class), $container->get(LayoutRegistry::class), $this->types(), $this->data(), sets: $container->get(AreaLayoutSets::class), blocks: $container->get(BlockRegistry::class)))->lines();

        $this->assertSame([
            'Type "page": 2 records use the layout "gone" in an areas field, which the area layout set "grid" does not have (they show with the set\'s layout for their number of areas): About (p1), Team (p2).',
            'Type "page": 1 record has blocks in areas their areas field\'s layout does not show (c2): Narrow (p3). Open them in the editor ("Not shown in this layout").',
        ], $lines);
        $warnings = $container->get(\Preflow\Data\Migration\MigrationRegistry::class)->warnings();
        $this->assertSame($lines, ($warnings['blocks.layouts'])(), 'what migrate and db:check print');
    }

    public function test_api_write_of_an_areas_field_named_areas_keeps_the_columns_it_does_not_name(): void
    {
        // The page's own areas field is called "areas": the controller merges it with mergeField(), never copies it.
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'titleField' => 'title',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'areas' => ['type' => 'areas', 'layouts' => 'grid']],
        ]));
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', 'areas' => ['layout' => '1-1-1', 'areas' => [
            'c1' => [self::section(self::A)], 'c2' => [], 'c3' => [self::section(self::B)],
        ]]]);

        $write = $this->json('PATCH', '/api/pages/p1', ['areas' => ['areas' => ['c2' => [['_block' => 'section', 'title' => ['en' => 'New']]]]]])->assertOk();

        $this->assertSame([self::A], array_column($write->json('data.areas.areas.c1'), '_uid'), 'a column the body does not name keeps its blocks');
        $this->assertSame([self::B], array_column($write->json('data.areas.areas.c3'), '_uid'));
        $stored = AreasValue::fromStored($this->stored('p1')?->get('areas'));
        $this->assertSame('1-1-1', $stored->layout);
        $this->assertSame([self::A], array_column($stored->areas['c1'], '_uid'));
        $this->assertSame([self::B], array_column($stored->areas['c3'], '_uid'));
        $this->assertCount(1, $stored->areas['c2']);
    }

    public function test_api_reads_and_writes_an_areas_field(): void
    {
        $this->store(['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', 'columns' => ['layout' => '1-1-1', 'areas' => [
            'c1' => [self::section(self::A, ['kind' => 'text', 'button_url' => 'https://example.com/kept'])],
            'c2' => [],
            'c3' => [self::section(self::B)],
        ]]]);

        $read = $this->json('GET', '/api/pages/p1')->assertOk();
        $this->assertSame('1-1-1', $read->json('data.columns.layout'));
        $this->assertSame(['key' => '1-1-1', 'fallback' => false], $read->json('data.columns.resolved'));
        $this->assertSame(['c1', 'c2', 'c3'], array_keys($read->json('data.columns.areas')));
        $this->assertNull($read->json('data.columns.areas.c1.0.button_url'), 'a field its show_when hides reads null');
        $this->assertStringContainsString('"unplaced":{}', $read->body(), 'an empty map is an object');

        // Two columns: c3 is kept, not shown; a column the write does not name keeps its blocks.
        $write = $this->json('PATCH', '/api/pages/p1', ['columns' => ['layout' => '1-1', 'areas' => ['c2' => [['_block' => 'section', 'title' => ['en' => 'New']]]]]])->assertOk();
        $this->assertSame(['key' => '1-1', 'fallback' => false], $write->json('data.columns.resolved'));
        $this->assertSame([self::A], array_column($write->json('data.columns.areas.c1'), '_uid'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $write->json('data.columns.areas.c2.0._uid'), 'a new block gets a fresh uid');
        $this->assertSame([self::B], array_column($write->json('data.columns.unplaced.c3'), '_uid'));
        $stored = AreasValue::fromStored($this->stored('p1')?->get('columns'));
        $this->assertSame('https://example.com/kept', $stored->areas['c1'][0]['button_url'], 'not written: the stored hidden value stays');

        // A hidden field keeps its stored value when the block is written.
        $this->json('PATCH', '/api/pages/p1', ['columns' => ['areas' => ['c1' => [['button_url' => 'https://example.com/sent'] + $stored->areas['c1'][0]]]]])->assertOk();
        $this->assertSame('https://example.com/kept', AreasValue::fromStored($this->stored('p1')?->get('columns'))->areas['c1'][0]['button_url']);

        $unknown = $this->json('PATCH', '/api/pages/p1', ['columns' => ['areas' => ['c9' => [self::section(self::C)]]]])->assertStatus(422);
        $this->assertSame(['columns.areas.c9' => ['The layout "1-1" has no area "c9". Use one of: c1, c2.']], $unknown->json('errors'));
        $twice = $this->json('PATCH', '/api/pages/p1', ['columns' => ['areas' => ['c2' => [self::section(self::C), self::section(self::C)]]]])->assertStatus(422);
        $this->assertSame(['columns.areas.c2.1._uid'], array_keys($twice->json('errors')));
        $taken = $this->json('PATCH', '/api/pages/p1', ['columns' => ['areas' => ['c2' => [self::section(self::B)]]]])->assertStatus(422);
        $this->assertSame(['columns.areas.c2.0._uid'], array_keys($taken->json('errors')), 'c3, which the write does not name, still holds it');
        $shape = $this->json('PATCH', '/api/pages/p1', ['columns' => ['layout' => 3, 'areas' => ['c1' => 'text']]])->assertStatus(422);
        $this->assertSame(['columns.layout', 'columns.areas.c1'], array_keys($shape->json('errors')));
        $this->assertSame(['columns'], array_keys($this->json('PATCH', '/api/pages/p1', ['columns' => 'text'])->assertStatus(422)->json('errors')));
        $layout = $this->json('PATCH', '/api/pages/p1', ['columns' => ['layout' => 'wide']])->assertStatus(422);
        $this->assertSame(['columns.layout' => ['The layout "wide" is not one of the layouts of the set "grid" (full, 1-1, 2-1, 1-1-1). Choose one of them.']], $layout->json('errors'), 'the core\'s rule, as for Folio');
        $required = $this->json('PATCH', '/api/pages/p1', ['columns' => ['areas' => ['c2' => [self::section(self::C, ['title' => ['en' => '']])]]]])->assertStatus(422);
        $this->assertSame(['columns.areas.c2.' . self::C . '.title' => ['This field is required.']], $required->json('errors'));
        $this->assertSame('1-1', AreasValue::fromStored($this->stored('p1')?->get('columns'))->layout, 'nothing was saved');
    }

    public function test_description_lists_the_areas_field_and_its_set(): void
    {
        $description = json_decode((string) json_encode($this->app()->container()->get(ContentDescription::class)->toArray()), true);

        $field = $description['blocks']['grid']['fields']['columns'];
        $this->assertSame(['type' => 'areas', 'label' => 'Columns', 'help' => null, 'translatable' => false, 'required' => false, 'list' => false, 'config' => ['layouts' => 'grid'], 'value' => 'areas_value'], $field);
        $this->assertSame('full', $description['area_layout_sets']['grid']['fallback']);
        $this->assertSame(['full', '1-1', '2-1', '1-1-1'], array_keys($description['area_layout_sets']['grid']['layouts']));
        $this->assertSame(['label' => 'Column 2', 'label_params' => [], 'allowed' => [], 'except' => ['grid'], 'min' => null, 'max' => 1, 'width' => 1], $description['area_layout_sets']['grid']['layouts']['2-1']['areas']['c2'], 'a plain label: no parameters');
        $this->assertSame(2, $description['area_layout_sets']['grid']['layouts']['2-1']['areas']['c1']['width']);
        $this->assertArrayNotHasKey('value', $description['blocks']['section']['fields']['quotes'], 'only an areas field names a value shape');
    }

    /**
     * A valid section, with $values over it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function section(string $uid, array $values = []): array
    {
        return array_replace(['_uid' => $uid, '_block' => 'section', 'title' => ['en' => 'Hello']], $values);
    }

    /** @return array<string, mixed> a grid with an empty column */
    private static function nested(string $uid): array
    {
        return ['_uid' => $uid, '_block' => 'grid', 'columns' => ['layout' => 'full', 'areas' => ['c1' => []]]];
    }

    /**
     * The refusal of the core's write validation for a page with these values.
     *
     * @param array<string, mixed> $values
     * @return array<string, list<string>>
     */
    private function errorsOfSave(array $values): array
    {
        try {
            $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), $values + ['title' => 'About']));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** @param array<string, mixed> $values a page stored without validation */
    private function store(array $values): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), $values), validate: false);
    }

    /** @param array<string, mixed>|null $body */
    private function json(string $method, string $uri, ?array $body = null): TestResponse
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://localhost' . $uri)->withHeader('Accept', 'application/json');
        if ($body !== null) {
            $request = $request->withHeader('Authorization', 'Bearer api-test-token')->withHeader('Content-Type', 'application/json')->withBody($factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return new TestResponse($this->fresh()->handle($request));
    }

    private function stored(string $id): ?DynamicRecord
    {
        return $this->fresh()->container()->get(DataManager::class)->findType('page', $id);
    }

    /** A newly booted app on the same files: one per request, as the server boots one. */
    private function fresh(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
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
