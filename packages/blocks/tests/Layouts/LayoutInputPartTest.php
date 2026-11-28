<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Preflow\Blocks\Layouts\LayoutInput;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Records\InputPart;
use Preflow\Data\Records\RecordInput;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\ValidationException;

/**
 * A page's layout and areas as the blocks package's part of the one write class (0.93):
 * LayoutInput is an InputPart registered on RecordParts, so RecordInput takes "_layout" and
 * "_areas" for a type with layouts and merges them exactly as LayoutInput::merge() did in 0.92
 * (the same values, the same refusals with the same keys).
 */
final class LayoutInputPartTest extends BlocksAppTestCase
{
    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';
    private const string C = 'cccccccccccccccc';
    private const string D = 'dddddddddddddddd';
    private const string E = 'eeeeeeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn [\n"
            . "    'classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\Section::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\SectionQuote::class],\n"
            . "    'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Home::class],\n"
            . "];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
            'layouts' => ['allowed' => ['landing', 'promo', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'home']],
        ]));
        $this->write('config/models/note.json', (string) json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function bodies(): iterable
    {
        yield 'the layout alone' => [['_layout' => 'promo']];
        yield 'the layout to the default' => [['_layout' => null]];
        yield 'one area, the others kept' => [['title' => 'Edited', '_areas' => ['main' => [self::section(self::B, ['title' => ['en' => 'Edited']])]]]];
        yield 'an area emptied with another layout' => [['_layout' => 'standard', '_areas' => ['main' => []]]];
        yield 'a hidden block field keeps its value' => [['_areas' => ['main' => [self::section(self::B, ['kind' => 'text', 'button_url' => 'https://example.com/new'])]]]];
        yield 'an unknown area' => [['_areas' => ['sidebar' => [self::section(self::B)]]]];
        yield 'wrong shapes' => [['_layout' => 7, '_areas' => ['main' => [['title' => 'no block key'], 'text']]]];
        yield 'a uid given twice' => [['_areas' => ['main' => [self::section(self::B), self::section(self::B)]]]];
        yield 'a uid an unnamed area holds' => [['_areas' => ['main' => [self::section(self::A)]]]];
        yield 'not a uid' => [['_areas' => ['main' => [self::section('intro')]]]];
    }

    /** @param array<string, mixed> $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodies')]
    public function test_layout_keys_merge_like_layout_input_0_92(array $body): void
    {
        $stored = ['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'landing', '_areas' => [
            'hero' => [self::section(self::A)],
            'main' => [self::section(self::B, ['kind' => 'link', 'button_url' => 'https://example.com/kept'])],
        ]];
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), $stored), validate: false);
        $record = $this->data()->findType('page', 'p1') ?? self::fail('no page');
        $c = $this->app()->container();
        $layoutInput = $c->get(LayoutInput::class);
        $keys = array_flip(['_layout', '_areas']);

        $expected = null;
        $refused = null;
        try {
            $expected = array_intersect_key($layoutInput->merge($record->getType(), $record->toArray(), $body), $keys);
        } catch (ValidationException $e) {
            $refused = $e->errors();
        }

        try {
            $written = $c->get(RecordInput::class)->update($record, $body);
            $this->assertNull($refused, 'refused by LayoutInput 0.92, taken by the part');
            $this->assertSame($expected, array_intersect_key($written->toArray(), $keys));
        } catch (ValidationException $e) {
            $this->assertSame($refused, $e->errors(), 'the same refusals, keyed alike');
        }
    }

    public function test_layout_input_is_the_registered_input_part(): void
    {
        $c = $this->app()->container();
        $layoutInput = $c->get(LayoutInput::class);
        $parts = $c->get(RecordParts::class);

        $this->assertInstanceOf(InputPart::class, $layoutInput);
        $this->assertSame(['_layout', '_areas'], $layoutInput->keys($this->types()->get('page')));
        $this->assertSame([], $layoutInput->keys($this->types()->get('note')), 'a type without layouts takes none of the keys');
        $this->assertSame(['_layout' => $layoutInput, '_areas' => $layoutInput], $parts->inputKeys($this->types()->get('page')));
    }

    public function test_a_type_without_layouts_refuses_the_layout_keys_as_no_field(): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('note'), ['uuid' => 'n1', 'title' => 'Note']), validate: false);
        $note = $this->data()->findType('note', 'n1') ?? self::fail('no note');

        try {
            $this->app()->container()->get(RecordInput::class)->update($note, ['_layout' => 'standard']);
            self::fail('refused');
        } catch (ValidationException $e) {
            $this->assertSame(['_layout' => ['The type "Note" has no field "_layout".']], $e->errors());
        }
    }

    public function test_hidden_block_field_sent_is_refused(): void
    {
        $page = $this->storedPage(['main' => [self::section(self::B, ['secret' => 'kept'])]]);

        try {
            $this->app()->container()->get(RecordInput::class)->update($page, ['_areas' => ['main' => [self::section(self::B, ['secret' => 'guess'])]]]);
            self::fail('a hidden block field was taken');
        } catch (ValidationException $e) {
            $this->assertSame(['_areas.main.' . self::B . '.secret' => ['The type "Section" has no field "secret".']], $e->errors());
        }

        $folio = $this->app()->container()->get(LayoutInput::class)->merge($page->getType(), $page->toArray(), ['_areas' => ['main' => [self::section(self::B, ['secret' => 'edited'])]]], hiddenFields: true);
        $this->assertSame('edited', $folio['_areas']['main'][0]['secret'], 'an admin form that edits hidden fields gives them (Folio)');
    }

    public function test_block_written_without_its_hidden_field_keeps_the_stored_value(): void
    {
        $page = $this->storedPage(['main' => [self::section(self::B, ['secret' => 'kept'])]]);

        $written = $this->app()->container()->get(RecordInput::class)->update($page, ['_areas' => ['main' => [self::section(self::B, ['title' => ['en' => 'Edited']]), self::section(self::C)]]])->toArray();

        $this->assertSame('kept', $written['_areas']['main'][0]['secret']);
        $this->assertSame(['en' => 'Edited'], $written['_areas']['main'][0]['title']);
        $this->assertArrayNotHasKey('secret', $written['_areas']['main'][1], 'a new block has none');
    }

    public function test_doubled_uid_in_a_nested_list_is_refused(): void
    {
        $page = $this->storedPage([]);

        $this->assertSame(
            ['_areas.main.0.quotes.1._uid' => [self::twice(self::C)]],
            $this->refusals($page, ['_areas' => ['main' => [self::section(self::B, ['quotes' => [self::quote(self::C), self::quote(self::C)]])]]]),
        );
        $this->assertSame(
            ['_areas.main.1.quotes.0._uid' => [self::twice(self::C)]],
            $this->refusals($page, ['_areas' => ['main' => [self::section(self::B, ['quotes' => [self::quote(self::C)]]), self::section(self::D, ['quotes' => [self::quote(self::C)]])]]]),
            'in the nested lists of two blocks',
        );
    }

    public function test_nested_uid_equal_to_a_top_level_uid_is_refused(): void
    {
        $page = $this->storedPage(['hero' => [self::section(self::A, ['quotes' => [self::quote(self::E)]])]]);

        $this->assertSame(
            ['_areas.main.0.quotes.0._uid' => [self::twice(self::B)]],
            $this->refusals($page, ['_areas' => ['main' => [self::section(self::B, ['quotes' => [self::quote(self::B)]])]]]),
        );
        $this->assertSame(
            ['_areas.main.0.quotes.0._uid' => [self::twice(self::E)]],
            $this->refusals($page, ['_areas' => ['main' => [self::section(self::B, ['quotes' => [self::quote(self::E)]])]]]),
            'a nested uid an area the write does not name holds',
        );
    }

    /** @param array<string, list<array<string, mixed>>> $areas */
    private function storedPage(array $areas): DynamicRecord
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), ['uuid' => 'p1', 'title' => 'About', 'slug' => 'about', '_layout' => 'landing', '_areas' => $areas]), validate: false);

        return $this->data()->findType('page', 'p1') ?? self::fail('no page');
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, list<string>>
     */
    private function refusals(DynamicRecord $page, array $body): array
    {
        try {
            $this->app()->container()->get(RecordInput::class)->update($page, $body);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    private static function twice(string $uid): string
    {
        return 'The uid "' . $uid . '" is given to more than one block. Give each block its own "_uid", or leave it out for a new one.';
    }

    /** @return array<string, mixed> */
    private static function quote(string $uid): array
    {
        return ['_uid' => $uid, '_block' => 'section_quote', 'text' => 'Said'];
    }

    /** @param array<string, mixed> $values */
    private static function section(string $uid, array $values = []): array
    {
        return array_replace(['_uid' => $uid, '_block' => 'section', 'title' => ['en' => 'Hello']], $values);
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
