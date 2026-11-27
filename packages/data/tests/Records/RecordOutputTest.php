<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Records;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Records\ListsRecordReferences;
use Preflow\Data\Records\OutputPart;
use Preflow\Data\Records\ReadOptions;
use Preflow\Data\Records\RecordOutput;
use Preflow\Data\Records\RecordParts;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/**
 * A record of any type read out as an API answers with it (0.93, version 1): "_id", each field in
 * its output shape (FieldValue::toOutput()), whatever older data the driver holds, and the keys
 * the packages' output parts add. Without any other package installed.
 */
final class RecordOutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/record_output_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    /** The fields of every core shape, plus a site type without a value (raw) and a show_when field. */
    private const array FIELDS = [
        'title' => ['type' => 'string', 'translatable' => true],
        'body' => ['type' => 'richtext', 'translatable' => true],
        'summary' => ['type' => 'text'],
        'count' => ['type' => 'number'],
        'price' => ['type' => 'number'],
        'active' => ['type' => 'boolean'],
        'day' => ['type' => 'date'],
        'kind' => ['type' => 'select', 'options' => ['plain' => 'Plain', 'link' => 'Link']],
        'tags' => ['type' => 'tags'],
        'author' => ['type' => 'relation', 'relation' => ['to' => 'person']],
        'people' => ['type' => 'relation', 'relation' => ['to' => 'person', 'multiple' => true]],
        'photo' => ['type' => 'asset'],
        'photos' => ['type' => 'asset', 'asset' => ['multiple' => true]],
        'swatch' => ['type' => 'swatch'],
        'url' => ['type' => 'string', 'show_when' => ['field' => 'kind', '=' => 'link']],
    ];

    /** A row as older data holds it, written straight to the driver: JSON text, a comma string, numeric text, unclean HTML. */
    private const array OLDER_ROW = [
        'uuid' => 'r1',
        'title' => '{"en":"Hello","de":"Hallo"}',
        'body' => '{"en":"<p>Hi<\/p><script>alert(1)<\/script>","de":""}',
        'summary' => 'Plain text',
        'count' => '3',
        'price' => '2.50',
        'active' => '1',
        'day' => '2026-11-27',
        'kind' => 'link',
        'tags' => 'red, green',
        'author' => 'p1',
        'people' => '["p1","p2"]',
        'photo' => 'i1',
        'photos' => '["i1","i2"]',
        'swatch' => 'teal',
        'url' => 'https://example.org/',
    ];

    /** What every storage reads it out as. */
    private const array EXPECTED = [
        '_id' => 'r1',
        'title' => ['en' => 'Hello', 'de' => 'Hallo'],
        'body' => ['en' => '<p>Hi</p>', 'de' => ''],
        'summary' => 'Plain text',
        'count' => 3,
        'price' => 2.5,
        'active' => true,
        'day' => '2026-11-27',
        'kind' => 'link',
        'tags' => ['red', 'green'],
        'author' => 'p1',
        'people' => ['p1', 'p2'],
        'photo' => 'i1',
        'photos' => ['i1', 'i2'],
        'swatch' => 'teal',
        'url' => 'https://example.org/',
    ];

    #[DataProvider('storages')]
    public function test_output_is_the_same_on_json_and_sqlite(string $storage): void
    {
        $dm = $this->dm($storage);
        $dm->driver($storage)->save('items', 'r1', self::OLDER_ROW);

        $output = $this->reader($dm)->toArray($this->find($dm, 'r1'));

        $this->assertSame(self::EXPECTED, $output, "older data on {$storage}");
        $this->assertSame($output, json_decode((string) json_encode($output, JSON_THROW_ON_ERROR), true), 'plain JSON data');

        // The same values saved through the core read out the same.
        $saved = self::OLDER_ROW;
        $saved['uuid'] = 'r2';
        $saved['title'] = ['en' => 'Hello', 'de' => 'Hallo'];
        $saved['body'] = ['en' => '<p>Hi</p>', 'de' => ''];
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('item'), $saved), validate: false);
        $this->assertSame(['_id' => 'r2'] + self::EXPECTED, $this->reader($dm)->toArray($this->find($dm, 'r2')), "saved through saveType() on {$storage}");
    }

    #[DataProvider('storages')]
    public function test_missing_values_read_as_each_shapes_empty_value(string $storage): void
    {
        $dm = $this->dm($storage);
        $dm->driver($storage)->save('items', 'r3', ['uuid' => 'r3', 'kind' => 'plain', 'count' => '', 'active' => '', 'tags' => '', 'people' => '']);

        $output = $this->reader($dm)->toArray($this->find($dm, 'r3'));

        $this->assertSame(['en' => null, 'de' => null], $output['title'], 'every language of the type, as null without a value');
        $this->assertNull($output['count']);
        $this->assertNull($output['active']);
        $this->assertSame([], $output['tags'], 'a list shape without a value is []');
        $this->assertNull($output['people']);
        $this->assertNull($output['author']);
        $this->assertNull($output['swatch']);
    }

    public function test_translatable_field_is_a_map_or_one_locale_with_fallback(): void
    {
        $dm = $this->dm('json');
        $dm->driver('json')->save('items', 'r1', self::OLDER_ROW);
        $record = $this->find($dm, 'r1');
        $output = $this->reader($dm);

        $map = $output->toArray($record);
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], $map['title']);

        $de = $output->toArray($record, new ReadOptions(locale: 'de'));
        $this->assertSame('Hallo', $de['title']);
        $this->assertSame('<p>Hi</p>', $de['body'], 'no German body: the fallback chain gives the English one');
        $this->assertSame(3, $de['count'], 'a field that is not translatable is the same in every language');

        $fr = $output->toArray($record, new ReadOptions(locale: 'fr'));
        $this->assertSame('Hello', $fr['title'], 'a language the type lacks: the fallback, then the default');

        $one = $output->field($dm->getTypeRegistry()->get('item')->fields['title'], '{"en":"Hello","de":"Hallo"}', new ReadOptions(locale: 'de'), new Locales(['en', 'de'], 'en'));
        $this->assertSame('Hallo', $one, 'field() reads one stored value the same way');
    }

    public function test_translatable_field_on_a_single_language_site_is_a_plain_value(): void
    {
        $dm = $this->dm('json', new Locales(['en'], 'en'));
        $dm->driver('json')->save('items', 'r1', ['uuid' => 'r1', 'title' => 'Hello', 'body' => '{"en":"<p>Kept</p>"}']);
        $def = $dm->getTypeRegistry()->get('item');

        $output = $this->reader($dm)->toArray($this->find($dm, 'r1'));

        $this->assertSame([], $def->translatableFields(), 'the description says "translatable": false here');
        $this->assertSame('Hello', $output['title'], 'a plain value, not a locale map');
        $this->assertSame('<p>Kept</p>', $output['body'], 'a map stored while the site had more languages: its default language\'s value');
        $this->assertSame('Hello', $this->reader($dm)->toArray($this->find($dm, 'r1'), new ReadOptions(locale: 'en'))['title']);
    }

    public function test_hidden_field_reads_null(): void
    {
        $dm = $this->dm('json');
        $dm->driver('json')->save('items', 'r1', ['kind' => 'plain'] + self::OLDER_ROW);

        $output = $this->reader($dm)->toArray($this->find($dm, 'r1'));

        $this->assertArrayHasKey('url', $output);
        $this->assertNull($output['url'], 'its show_when hides it: null, though a value is stored');
        $this->assertSame('https://example.org/', $this->find($dm, 'r1')->get('url'), 'the stored value is kept');
    }

    #[DataProvider('storages')]
    public function test_relations_and_assets_are_ids(string $storage): void
    {
        $dm = $this->dm($storage);
        $dm->driver($storage)->save('items', 'r1', ['uuid' => 'r1', 'author' => 7, 'people' => '[1,"p2",["x"],"",null,true]', 'photo' => 'i1', 'photos' => 'i1, i2']);

        $output = $this->reader($dm)->toArray($this->find($dm, 'r1'));

        $this->assertSame('7', $output['author'], 'a number id as its text');
        $this->assertSame(['1', 'p2'], $output['people'], 'id_list: each a string; what is no id is left out');
        $this->assertSame('i1', $output['photo'], 'an asset field is its id, as a relation');
        $this->assertSame(['i1', 'i2'], $output['photos']);
        $this->assertSame(
            ['person' => ['7', '1', 'p2'], 'asset' => ['i1', 'i2']],
            $this->reader($dm)->references($this->find($dm, 'r1')),
            'the records the fields reference, by target type, each id once',
        );
    }

    public function test_rich_text_output_is_cleaned_even_if_stored_unclean(): void
    {
        foreach (['json', 'sqlite'] as $storage) {
            $dm = $this->dm($storage);
            // A script's import, straight to the driver: no typed write cleaned it.
            $dm->driver($storage)->save('items', 'r9', ['uuid' => 'r9', 'body' => '{"en":"<p onclick=\"x()\">Hi <a href=\"javascript:alert(1)\">there<\/a><\/p><script>alert(1)<\/script>","de":"<b>Fett<\/b>"}']);

            $output = $this->reader($dm)->toArray($this->find($dm, 'r9'));

            $this->assertSame(['en' => '<p>Hi <a>there</a></p>', 'de' => '<b>Fett</b>'], $output['body'], $storage);
            $this->assertStringContainsString('<script>', (string) $this->find($dm, 'r9')->get('body'), 'reading does not write');
        }
    }

    public function test_parts_add_their_keys(): void
    {
        $dm = $this->dm('json');
        $dm->driver('json')->save('items', 'r1', self::OLDER_ROW);
        $parts = new RecordParts();
        $built = 0;
        $parts->output(static function () use (&$built): OutputPart {
            $built++;

            return new FixedPart(['_extra', '_more'], ['_extra' => ['n' => 1], '_more' => 'yes']);
        });
        $parts->output(static fn (): OutputPart => new FixedPart([], []));
        $this->assertSame(0, $built, 'a part is built on first use');

        $output = (new RecordOutput($dm, $parts))->toArray($this->find($dm, 'r1'));

        $this->assertSame([...array_keys(self::EXPECTED), '_extra', '_more'], array_keys($output), '"_id", the fields in declared order, then the parts\' keys');
        $this->assertSame(['n' => 1], $output['_extra']);
        $this->assertSame(1, $built);
        $this->assertCount(2, $parts->outputs());
        $this->assertSame(1, RecordOutput::VERSION);
    }

    public function test_a_part_key_without_underscore_is_refused(): void
    {
        $dm = $this->dm('json');
        $dm->driver('json')->save('items', 'r1', self::OLDER_ROW);
        $parts = new RecordParts();
        $parts->output(static fn (): OutputPart => new FixedPart(['extra'], ['extra' => 1]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"extra"');
        (new RecordOutput($dm, $parts))->toArray($this->find($dm, 'r1'));
    }

    /** @return iterable<string, array{list<list<string>>, array<string, mixed>, string}> */
    public static function badParts(): iterable
    {
        yield 'two parts claim one key' => [[['_extra'], ['_extra']], [], '"_extra"'];
        yield 'the record\'s own id key' => [[['_id']], [], '"_id"'];
        yield 'a key that is also a field' => [[['_title']], [], '"_title"'];
        yield 'a key it did not declare' => [[['_extra']], ['_extra' => 1, '_other' => 2], '"_other"'];
    }

    /**
     * @param list<list<string>> $keys
     * @param array<string, mixed> $read
     */
    #[DataProvider('badParts')]
    public function test_part_keys_that_cannot_work_are_refused(array $keys, array $read, string $named): void
    {
        $this->model('note', ['_title' => ['type' => 'string']]);
        $dm = $this->dm('json');
        $dm->driver('json')->save('items', 'r1', self::OLDER_ROW);
        $parts = new RecordParts();
        foreach ($keys as $one) {
            $parts->output(static fn (): OutputPart => new FixedPart($one, $read));
        }
        $type = $named === '"_title"' ? 'note' : 'item';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($named);
        (new RecordOutput($dm, $parts))->toArray($type === 'note' ? new DynamicRecord($dm->getTypeRegistry()->get('note'), ['uuid' => 'n1']) : $this->find($dm, 'r1'));
    }

    public function test_parts_list_the_records_their_keys_reference(): void
    {
        $dm = $this->dm('json');
        $dm->driver('json')->save('items', 'r1', ['uuid' => 'r1', 'photo' => 'i1', 'kind' => 'plain']);
        $parts = new RecordParts();
        $parts->output(static fn (): OutputPart => new ReferencingPart(['asset' => ['i1', 'i3'], 'person' => ['p9']]));

        $this->assertSame(['asset' => ['i1', 'i3'], 'person' => ['p9']], (new RecordOutput($dm, $parts))->references($this->find($dm, 'r1')));
    }

    public function test_a_hidden_field_references_nothing(): void
    {
        $this->model('item', ['kind' => ['type' => 'string'], 'photo' => ['type' => 'asset', 'show_when' => ['field' => 'kind', '=' => 'photo']]]);
        $dm = $this->dm('json', fields: false);
        $dm->driver('json')->save('items', 'r1', ['uuid' => 'r1', 'kind' => 'text', 'photo' => 'i1']);

        $this->assertSame([], $this->reader($dm)->references($this->find($dm, 'r1')));
    }

    private function reader(DataManager $dm): RecordOutput
    {
        return new RecordOutput($dm, new RecordParts());
    }

    private function find(DataManager $dm, string $id): DynamicRecord
    {
        $record = $dm->findType('item', $id);
        $this->assertNotNull($record);

        return $record;
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function model(string $key, array $fields): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", (string) json_encode([
            'key' => $key, 'table' => $key === 'item' ? 'items' : $key, 'storage' => 'json', 'id_field' => 'uuid', 'fields' => $fields,
        ]));
    }

    private function dm(string $storage, ?Locales $locales = null, bool $fields = true): DataManager
    {
        if ($fields) {
            $this->model('item', self::FIELDS);
        }
        // The type's storage is the one under test.
        $schema = json_decode((string) file_get_contents($this->dir . '/models/item.json'), true);
        $schema['storage'] = $storage;
        file_put_contents($this->dir . '/models/item.json', (string) json_encode($schema));
        $driver = $storage === 'json'
            ? new JsonFileDriver($this->dir . '/data')
            : new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite'));
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models', $locales ?? new Locales(['en', 'de'], 'en')));
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('item'));
        }

        return $dm;
    }
}

/** An output part that declares $keys and reads $read for every record. */
final readonly class FixedPart implements OutputPart
{
    /**
     * @param list<string> $keys
     * @param array<string, mixed> $read
     */
    public function __construct(private array $keys, private array $read) {}

    public function keys(TypeDefinition $def): array
    {
        return $this->keys;
    }

    public function read(DynamicRecord $record, ReadOptions $options): array
    {
        return $this->read;
    }
}

/** An output part whose key references records (as a page's areas do). */
final readonly class ReferencingPart implements OutputPart, ListsRecordReferences
{
    /** @param array<string, list<string>> $references */
    public function __construct(private array $references) {}

    public function keys(TypeDefinition $def): array
    {
        return ['_refs'];
    }

    public function read(DynamicRecord $record, ReadOptions $options): array
    {
        return [];
    }

    public function references(DynamicRecord $record): array
    {
        return $this->references;
    }
}
