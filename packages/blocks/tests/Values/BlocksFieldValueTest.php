<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Values;

use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Layouts\LayoutResource;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Blocks\Values\AreasFieldValue;
use Preflow\Blocks\Values\BlocksFieldValue;
use Preflow\Data\DataManager;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CleansOnWrite;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\ListsReferences;
use Preflow\Data\Values\MergesInput;

/**
 * The values of a blocks field (shape "blocks") and an areas field (shape "areas_value"),
 * registered on the DataManager's FieldKinds by BlocksServiceProvider: each block field read and
 * written through its own value, nested lists included, and the records they reference.
 */
final class BlocksFieldValueTest extends BlocksAppTestCase
{
    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';
    private const string C = 'cccccccccccccccc';
    private const string G = '9a9a9a9a9a9a9a9a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\BlocksServiceProvider::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\GridLayouts::class];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Values\\Card::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\Grid::class], 'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class]];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'sections' => ['type' => 'blocks'], 'columns' => ['type' => 'areas', 'layouts' => 'grid']],
        ]));
    }

    private static function blocksField(): TypeFieldDefinition
    {
        return new TypeFieldDefinition('sections', 'blocks');
    }

    private static function areasField(): TypeFieldDefinition
    {
        return new TypeFieldDefinition('columns', 'areas', config: ['layouts' => 'grid']);
    }

    private function blocks(): BlocksFieldValue
    {
        $value = $this->app()->container()->get(DataManager::class)->fieldKinds()->valueOf(self::blocksField());
        $this->assertInstanceOf(BlocksFieldValue::class, $value);

        return $value;
    }

    private function areas(): AreasFieldValue
    {
        $value = $this->app()->container()->get(DataManager::class)->fieldKinds()->valueOf(self::areasField());
        $this->assertInstanceOf(AreasFieldValue::class, $value);

        return $value;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function card(string $uid, array $values = []): array
    {
        return ['_uid' => $uid, '_block' => 'value_card', ...$values];
    }

    public function test_registered_with_their_shapes_and_companions(): void
    {
        $blocks = $this->blocks();
        $areas = $this->areas();

        $this->assertSame('blocks', $blocks->shape(self::blocksField()));
        $this->assertSame('areas_value', $areas->shape(self::areasField()));
        $this->assertInstanceOf(CleansOnWrite::class, $blocks);
        $this->assertInstanceOf(ListsReferences::class, $blocks);
        $this->assertInstanceOf(CleansOnWrite::class, $areas);
        $this->assertInstanceOf(MergesInput::class, $areas);
        $this->assertInstanceOf(ListsReferences::class, $areas);
        $this->assertInstanceOf(DescribesShapes::class, $blocks);
        $this->assertSame(['blocks'], array_keys($blocks->shapes()));
        $this->assertSame(['areas_value'], array_keys($areas->shapes()));
    }

    public function test_blocks_output_reads_nested_values_through_their_kinds(): void
    {
        $stored = (string) json_encode([
            self::card(self::A, ['count' => '3', 'author' => 7, 'images' => '["i1","i2"]', 'body' => ['en' => '<p onclick="x()">Hi</p>', 'de' => '<p>Hallo</p>'], 'kind' => 'plain', 'url' => '/stored-only', 'children' => [
                self::card(self::B, ['count' => '2.50', 'kind' => 'link', 'url' => '/shown']),
            ]]),
            ['_uid' => self::C, '_block' => 'retired', 'count' => '3'],
        ]);

        $out = $this->blocks()->toOutput($stored, self::blocksField());

        $card = $out[0];
        $this->assertSame([self::A, 'value_card'], [$card['_uid'], $card['_block']], 'a block keeps its uid and key');
        $this->assertSame(3, $card['count'], 'a number stored as text reads as a number');
        $this->assertSame('7', $card['author'], 'a relation reads as its id');
        $this->assertSame(['i1', 'i2'], $card['images'], 'a media list stored as JSON text reads as a list');
        $this->assertSame(['en' => '<p>Hi</p>', 'de' => '<p>Hallo</p>'], $card['body'], 'locale maps kept, each locale through the value (rich text cleaned)');
        $this->assertNull($card['url'], 'a field its show_when hides is null');
        $this->assertSame(2.5, $card['children'][0]['count'], 'nested blocks too');
        $this->assertSame('/shown', $card['children'][0]['url']);
        $this->assertSame(['_uid' => self::C, '_block' => 'retired', 'count' => '3'], $out[1], 'an unknown block as stored');
        $this->assertSame([], $this->blocks()->toOutput(null, self::blocksField()), 'no blocks: an empty list');
    }

    public function test_areas_output_is_the_layout_resource_s_with_values_read(): void
    {
        $stored = ['layout' => '1-1', 'areas' => ['c1' => [self::card(self::A, ['count' => '3'])], 'c2' => [], 'c3' => [self::card(self::B)]]];

        $out = $this->areas()->toOutput((string) json_encode($stored), self::areasField());

        $resource = $this->app()->container()->get(LayoutResource::class)->areasField(self::areasField(), $stored);
        $this->assertSame(['layout', 'resolved', 'areas', 'unplaced'], array_keys($out));
        $this->assertSame($resource['layout'], $out['layout']);
        $this->assertSame($resource['resolved'], $out['resolved']);
        $this->assertSame(['c1', 'c2'], array_keys($out['areas']));
        $this->assertSame(3, $out['areas']['c1'][0]['count']);
        $this->assertSame(['c3'], array_keys($out['unplaced']));
        $this->assertEquals(new \stdClass(), $this->areas()->toOutput(null, self::areasField())['unplaced'], 'an empty map is an object');
    }

    public function test_areas_merge_is_the_layout_input_s(): void
    {
        $stored = ['layout' => '1-1', 'areas' => ['c1' => [self::card(self::A)], 'c2' => [self::card(self::B)]]];
        $input = ['areas' => ['c2' => [self::card(self::C)]]];

        $this->assertSame(
            $this->app()->container()->get(LayoutInput::class)->mergeField(self::areasField(), $stored, $input),
            $this->areas()->merge($stored, $input, self::areasField()),
        );
    }

    public function test_to_stored_converts_block_values_and_passes_others_through(): void
    {
        $stored = $this->blocks()->toStored([self::card(self::A, ['count' => '3', 'body' => ['en' => '<p onclick="x()">Hi</p>']])], self::blocksField());

        $this->assertSame(3, $stored[0]['count']);
        $this->assertSame(['en' => '<p>Hi</p>'], $stored[0]['body']);
        $this->assertSame(42, $this->blocks()->toStored(42, self::blocksField()), 'what does not convert passes through for the rules');
        $this->assertSame('not json', $this->blocks()->toStored('not json', self::blocksField()));
    }

    public function test_clean_keeps_clean_values_and_json_text_as_given(): void
    {
        $json = (string) json_encode([self::card(self::A, ['note' => '<p>Fine</p>', 'count' => '3']), 'not a block']);
        $this->assertSame($json, $this->blocks()->clean($json, self::blocksField()), 'nothing to clean: the same text');

        $dirty = (string) json_encode([self::card(self::A, ['note' => '<p onclick="x()">Fine</p>'])]);
        $cleaned = $this->blocks()->clean($dirty, self::blocksField());
        $this->assertIsString($cleaned, 'JSON text stays JSON text');
        $this->assertSame('<p>Fine</p>', json_decode($cleaned, true)[0]['note']);

        $areas = ['layout' => 'full', 'areas' => ['c1' => [self::card(self::A, ['note' => '<p onclick="x()">Fine</p>'])]]];
        $this->assertSame('<p>Fine</p>', $this->areas()->clean($areas, self::areasField())['areas']['c1'][0]['note']);
        $this->assertNull($this->blocks()->clean(null, self::blocksField()));
    }

    public function test_references_of_blocks_and_columns(): void
    {
        $sections = [
            self::card(self::A, ['author' => 'p1', 'images' => ['i1'], 'children' => [self::card(self::B, ['author' => 'p2', 'images' => '["i2","i1"]'])]]),
            ['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => 'full', 'areas' => ['c1' => [self::card(self::C, ['author' => 'p3'])], 'c9' => [self::card(self::C, ['images' => ['i3']])]]]],
        ];

        $this->assertSame(['person' => ['p1', 'p2', 'p3'], 'asset' => ['i1', 'i2', 'i3']], $this->blocks()->references((string) json_encode($sections), self::blocksField()), 'every block field, nested blocks and columns (each area), each id once');
        $this->assertSame(['person' => ['p3']], $this->areas()->references(['layout' => 'full', 'areas' => ['c1' => [self::card(self::C, ['author' => 'p3'])]]], self::areasField()));
        $this->assertSame([], $this->blocks()->references(null, self::blocksField()));
    }
}
