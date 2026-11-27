<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Values;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Blocks\BlockTextCleaner;
use Preflow\Blocks\CoreBlockTextCleaner;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\FrozenClock;
use Preflow\Data\ChangeDates;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\ContextFactory;

/**
 * Rich text inside blocks is cleaned on every typed write in the core (0.93), as a record's own
 * rich text: a blocks field's blocks, nested ones, the columns of an areas field and a page's
 * "_areas" (DataManager::storageValues(), through BlocksFieldValue and the "_areas" system key),
 * on JSON and on SQLite (where the lists come back as JSON text). Without Folio, blocks render
 * through the core cleaner too (CoreBlockTextCleaner).
 */
final class BlockRichTextOnWriteTest extends BlocksAppTestCase
{
    private const string UNSAFE = '<p onclick="steal()">Hello <strong>you</strong><script>alert(1)</script></p>';

    private const string CLEAN = '<p>Hello <strong>you</strong></p>';

    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';
    private const string C = 'cccccccccccccccc';
    private const string G = '9a9a9a9a9a9a9a9a';

    protected function setUp(): void
    {
        parent::setUp();
        $sqlite = $this->dir . '/storage/pages.sqlite';
        @mkdir(dirname($sqlite), 0777, true);
        $pdo = new \PDO('sqlite:' . $sqlite);
        $pdo->exec('CREATE TABLE spage (uuid TEXT PRIMARY KEY, title TEXT, sections TEXT, columns TEXT, _layout TEXT, _areas TEXT, _updated_at TEXT)');
        $pdo = null;
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\BlocksServiceProvider::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\GridLayouts::class];\n");
        $this->write('config/data.php', "<?php\nreturn ['drivers' => [\n"
            . "    'json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data'],\n"
            . "    'sqlite' => ['driver' => 'sqlite', 'path' => " . var_export($sqlite, true) . "],\n"
            . "], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Values\\Card::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Values\\RichPanel::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Columns\\Grid::class], 'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class]];\n");
        foreach (['page' => 'json', 'spage' => 'sqlite'] as $key => $storage) {
            $this->write('config/models/' . $key . '.json', (string) json_encode([
                'key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid', 'titleField' => 'title',
                'fields' => [
                    'title' => ['type' => 'string'],
                    'sections' => ['type' => 'blocks'],
                    'columns' => ['type' => 'areas', 'layouts' => 'grid'],
                ],
                'layouts' => ['allowed' => ['standard'], 'default' => 'standard'],
            ]));
        }
    }

    /** @return array<string, array{string}> */
    public static function storages(): array
    {
        return ['JSON' => ['page'], 'SQLite' => ['spage']];
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }

    /** The row as the driver holds it, its JSON text decoded. @return array<string, mixed> */
    private function row(string $type, string $id): array
    {
        $def = $this->types()->get($type);
        $row = $this->data()->driver($def->storage)->findOne($def->table, $id) ?? [];
        foreach (['sections', 'columns', '_areas'] as $key) {
            if (is_string($row[$key] ?? null)) {
                $row[$key] = json_decode($row[$key], true);
            }
        }

        return $row;
    }

    /** The driver's row as stored, JSON text kept. @return array<string, mixed> */
    private function raw(string $type, string $id): array
    {
        $def = $this->types()->get($type);

        return $this->data()->driver($def->storage)->findOne($def->table, $id) ?? [];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private static function card(string $uid, array $values = []): array
    {
        return ['_uid' => $uid, '_block' => 'value_card', ...$values];
    }

    private static function at(string $at, \Closure $write): mixed
    {
        return Contexts::run(ContextFactory::forTest()->withClock(new FrozenClock(new \DateTimeImmutable($at))), $write);
    }

    /** @param array<string, mixed> $values */
    private function save(string $type, array $values, bool $validate = false): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), $values), $validate);
    }

    #[DataProvider('storages')]
    public function test_rich_text_in_a_block_is_cleaned_on_save_without_validation(string $type): void
    {
        $this->save($type, ['uuid' => 'p1', 'title' => 'T', 'sections' => [
            self::card(self::A, ['body' => ['en' => self::UNSAFE, 'de' => '<a href="javascript:x()">Hallo</a>'], 'note' => self::UNSAFE, 'children' => [
                self::card(self::B, ['note' => self::UNSAFE, 'children' => [self::card(self::C, ['body' => ['en' => self::UNSAFE]])]]),
            ]]),
        ]]);

        $card = $this->row($type, 'p1')['sections'][0];
        $this->assertSame(['en' => self::CLEAN, 'de' => '<a>Hallo</a>'], $card['body'], 'every locale of a translatable block field');
        $this->assertSame(self::CLEAN, $card['note']);
        $this->assertSame(self::CLEAN, $card['children'][0]['note'], 'a nested block');
        $this->assertSame(['en' => self::CLEAN], $card['children'][0]['children'][0]['body'], 'nested to any depth');
        $this->assertSame([self::A, 'value_card'], [$card['_uid'], $card['_block']]);
    }

    #[DataProvider('storages')]
    public function test_rich_text_in_a_column_and_a_page_area_is_cleaned(string $type): void
    {
        $this->save($type, [
            'uuid' => 'p1',
            'title' => 'T',
            'sections' => [['_uid' => self::G, '_block' => 'grid', 'columns' => ['layout' => '1-1', 'areas' => ['c1' => [self::card(self::A, ['note' => self::UNSAFE])], 'c2' => []]]]],
            'columns' => ['layout' => 'full', 'areas' => ['c1' => [self::card(self::B, ['body' => ['en' => self::UNSAFE]])]]],
            '_layout' => 'standard',
            '_areas' => ['main' => [self::card(self::C, ['note' => self::UNSAFE])], 'gone' => [self::card(self::A, ['note' => self::UNSAFE])]],
        ]);

        $row = $this->row($type, 'p1');
        $this->assertSame(self::CLEAN, $row['sections'][0]['columns']['areas']['c1'][0]['note'], 'an areas field in a block (a column)');
        $this->assertSame([], $row['sections'][0]['columns']['areas']['c2'], 'an empty column stays');
        $this->assertSame('1-1', $row['sections'][0]['columns']['layout']);
        $this->assertSame(['en' => self::CLEAN], $row['columns']['areas']['c1'][0]['body'], 'a record\'s own areas field');
        $this->assertSame(self::CLEAN, $row['_areas']['main'][0]['note'], 'a page area');
        $this->assertSame(self::CLEAN, $row['_areas']['gone'][0]['note'], 'an area the layout lacks too');
    }

    #[DataProvider('storages')]
    public function test_resaving_clean_block_rich_text_keeps_the_change_date(string $type): void
    {
        $values = [
            'uuid' => 'p1',
            'title' => 'T',
            'sections' => [self::card(self::A, ['body' => ['en' => self::UNSAFE, 'de' => '<p>Hallo</p>'], 'children' => [self::card(self::B, ['note' => self::UNSAFE])]])],
            'columns' => ['layout' => 'full', 'areas' => ['c1' => [self::card(self::C, ['note' => '<p>Ein <a href="https://example.com/x">Link</a></p>'])]]],
            '_layout' => 'standard',
            '_areas' => ['main' => [self::card(self::C, ['note' => self::UNSAFE])]],
        ];
        self::at('2026-02-01T10:00:00Z', fn () => $this->save($type, $values));
        $first = $this->raw($type, 'p1');
        $this->assertSame('2026-02-01T10:00:00+00:00', $first[ChangeDates::FIELD]);

        // Saved again by a path that hands the stored values back (JSON text on SQLite), and by one
        // that hands the unsafe input again: same bytes, the change date kept.
        self::at('2026-02-03T10:00:00Z', fn () => $this->data()->saveType($this->data()->findType($type, 'p1') ?? $this->fail('stored'), false));
        $this->assertSame($first, $this->raw($type, 'p1'), 'the stored blocks saved again');
        self::at('2026-02-04T10:00:00Z', fn () => $this->save($type, $values));
        $this->assertSame($first, $this->raw($type, 'p1'), 'the unsafe input again: cleaned to the same bytes');

        // A change moves it.
        $values['_areas']['main'][0]['note'] = '<p>New</p>';
        self::at('2026-02-05T10:00:00Z', fn () => $this->save($type, $values));
        $this->assertSame('2026-02-05T10:00:00+00:00', $this->raw($type, 'p1')[ChangeDates::FIELD]);
    }

    #[DataProvider('storages')]
    public function test_unknown_block_is_stored_as_given(string $type): void
    {
        $unknown = ['_uid' => self::A, '_block' => 'retired', 'body' => self::UNSAFE, 'extra' => ['x' => 1]];
        $this->save($type, ['uuid' => 'p1', 'sections' => [$unknown, self::card(self::B, ['note' => self::UNSAFE, 'stray' => self::UNSAFE])]]);

        $sections = $this->row($type, 'p1')['sections'];
        $this->assertSame($unknown, $sections[0], 'no class declares it: its values are not known, so not touched');
        $this->assertSame(self::UNSAFE, $sections[1]['stray'], 'a key the block does not declare is not touched');
        $this->assertSame(self::CLEAN, $sections[1]['note']);
    }

    /** 0.93.1: after the save the record holds what was stored, a block's rich text included. */
    #[DataProvider('storages')]
    public function test_the_saved_record_holds_the_cleaned_block_rich_text(string $type): void
    {
        $record = DynamicRecord::fromArray($this->types()->get($type), [
            'uuid' => 'p1',
            'title' => "it's",
            'sections' => [self::card(self::A, ['body' => ['en' => self::UNSAFE, 'de' => "<p>c'est<br></p>"], 'note' => "<p>it's</p>", 'count' => 3])],
            '_layout' => 'standard',
            '_areas' => ['main' => [self::card(self::C, ['note' => self::UNSAFE])]],
        ]);
        $this->data()->saveType($record, false);

        $card = $record->get('sections')[0];
        $this->assertSame(['en' => self::CLEAN, 'de' => '<p>c&#039;est<br /></p>'], $card['body'], 'every locale of a translatable block field');
        $this->assertSame('<p>it&#039;s</p>', $card['note']);
        $this->assertSame([self::A, 'value_card', 3], [$card['_uid'], $card['_block'], $card['count']], 'the rest of the block as given');
        $this->assertSame(self::CLEAN, $record->get('_areas')['main'][0]['note'], 'a page area');
        $this->assertSame("it's", $record->get('title'), 'no rich text: as given');
        $this->assertSame($this->row($type, 'p1')['sections'], $record->get('sections'), 'as stored');

        // Read back (JSON text on SQLite) and saved again: the record keeps the form it was read in.
        $read = $this->data()->findType($type, 'p1') ?? $this->fail('stored');
        $before = $read->toArray();
        $this->data()->saveType($read, false);
        $this->assertSame($before['sections'], $read->get('sections'));
    }

    public function test_render_without_folio_cleans_block_rich_text(): void
    {
        $this->assertInstanceOf(CoreBlockTextCleaner::class, $this->app()->container()->get(BlockTextCleaner::class), 'the default binding');
        $page = [['_uid' => self::A, '_block' => 'rich_panel', 'body' => ['en' => self::UNSAFE, 'de' => 'Hallo'], 'cards' => [self::card(self::B, ['note' => '<img src="/a.jpg" onerror="steal()">'])]]];

        $html = $this->render('{{ blocks(page) }}', ['page' => $page]);

        $this->assertStringContainsString('<div class="panel">' . self::CLEAN . '<aside><img src="/a.jpg" /></aside></div>', $html, 'imported or older data is cleaned on its way to the template');
        $this->assertStringNotContainsString('steal', $html);
        $this->assertStringNotContainsString('script', $html);
    }

    public function test_render_without_a_data_manager_cleans_too(): void
    {
        @unlink($this->dir . '/config/data.php');
        foreach (['page', 'spage'] as $key) {
            @unlink($this->dir . '/config/models/' . $key . '.json');
        }
        $this->assertFalse($this->app()->container()->bound(DataManager::class));

        $html = $this->render('{{ blocks(page) }}', ['page' => [['_uid' => self::A, '_block' => 'rich_panel', 'body' => self::UNSAFE]]]);

        $this->assertStringContainsString('<div class="panel">' . self::CLEAN . '</div>', $html);
    }
}
