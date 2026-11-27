<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Values\DescribesShapes;
use Preflow\Data\Values\FieldValue;
use Preflow\Data\Values\ListsReferences;
use Preflow\Fields\FieldsServiceProvider;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * The twelve field types' values in the core (0.93): an input value to what saveType() stores, a
 * stored value (JSON text on SQL) to the frozen output object, the shape's name and the
 * references of a link and an embed, with no Folio.
 */
final class FieldValuesTest extends TestCase
{
    private const TYPES = ['link', 'email', 'phone', 'color', 'code', 'json', 'datetime', 'time', 'daterange', 'embed', 'table', 'location'];

    private const VIDEO = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fields_values_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return iterable<string, array{string, mixed, mixed, mixed}> type, input, stored, output */
    public static function rows(): iterable
    {
        $link = ['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => 'About', 'new_tab' => false];
        yield 'link' => ['link', json_encode($link), $link, $link];
        yield 'email' => ['email', '  team@example.org ', 'team@example.org', 'team@example.org'];
        yield 'phone' => ['phone', '+41 44 123 45 67', ['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234567'], ['as_typed' => '+41 44 123 45 67', 'e164' => '+41441234567']];
        yield 'color' => ['color', '#C00', '#cc0000', '#cc0000'];
        yield 'code' => ['code', "<?php\n\techo 1;", "<?php\n\techo 1;", "<?php\n\techo 1;"];
        yield 'json' => ['json', ['b' => 1, 'a' => ['x', null]], "{\n    \"b\": 1,\n    \"a\": [\n        \"x\",\n        null\n    ]\n}", ['b' => 1, 'a' => ['x', null]]];
        yield 'datetime' => ['datetime', '2026-03-29T14:30:00+02:00', '2026-03-29T12:30:00Z', '2026-03-29T12:30:00Z'];
        yield 'time' => ['time', '9:30', '09:30', '09:30'];
        yield 'daterange' => ['daterange', ' {"start":"2026-03-01","end":"2026-03-05"}', ['start' => '2026-03-01', 'end' => '2026-03-05'], ['start' => '2026-03-01', 'end' => '2026-03-05']];
        $embed = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'url' => self::VIDEO, 'poster' => null];
        yield 'embed' => ['embed', self::VIDEO, $embed, $embed];
        $table = ['head' => ['Day', 'Hours'], 'rows' => [['Mon', '9-5'], ['Tue', ['de' => 'zu', 'en' => 'closed']]]];
        yield 'table' => ['table', ['head' => ['Day', 'Hours'], 'rows' => [['Mon', '9-5'], ['Tue']]], ['head' => ['Day', 'Hours'], 'rows' => [['Mon', '9-5'], ['Tue', '']]], ['head' => ['Day', 'Hours'], 'rows' => [['Mon', '9-5'], ['Tue', '']]]];
        yield 'table with languages' => ['table', $table, $table, $table];
        yield 'location' => ['location', ['lat' => '47.37', 'lng' => 8.54, 'address' => ' Bahnhofstrasse 1 '], ['lat' => 47.37, 'lng' => 8.54, 'address' => 'Bahnhofstrasse 1'], ['lat' => 47.37, 'lng' => 8.54, 'address' => 'Bahnhofstrasse 1']];
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    public function test_the_twelve_values_are_registered_with_their_shapes(): void
    {
        $kinds = new FieldKinds();
        FieldsServiceProvider::registerKinds($kinds);

        foreach (self::TYPES as $type) {
            $this->assertTrue($kinds->hasValue($type), $type);
            $this->assertInstanceOf(DescribesShapes::class, $kinds->valueOf($this->field($type)), $type);
        }
        $shapes = [];
        foreach (self::TYPES as $type) {
            $value = $kinds->valueOf($this->field($type));
            $shapes[$type] = $value->shape($this->field($type));
            $this->assertArrayHasKey($shapes[$type], $value->shapes(), $type);
        }
        $this->assertSame([
            'link' => 'link', 'email' => 'string', 'phone' => 'phone', 'color' => 'color', 'code' => 'string', 'json' => 'json',
            'datetime' => 'datetime', 'time' => 'time', 'daterange' => 'daterange', 'embed' => 'embed', 'table' => 'table', 'location' => 'location',
        ], $shapes);
    }

    /** @param mixed $input */
    #[DataProvider('rows')]
    public function test_each_value_round_trips(string $type, mixed $input, mixed $stored, mixed $output): void
    {
        $value = $this->value($type);
        $field = $this->field($type);

        $this->assertSame($stored, $value->toStored($input, $field));
        $this->assertSame($output, $value->toOutput($stored, $field));
        // Stored twice is stored once, and a SQL column's JSON text reads out the same.
        $this->assertSame($stored, $value->toStored($stored, $field));
        $text = is_array($stored) ? json_encode($stored) : $stored;
        $this->assertSame($output, $value->toOutput($text, $field));
        // The output is JSON-safe plain data.
        $this->assertSame($output, json_decode((string) json_encode($output), true));
    }

    #[DataProvider('storages')]
    public function test_each_value_reads_back_as_saved_on_every_storage(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('all');
        $n = 0;
        foreach (self::rows() as [$type, $input, , $output]) {
            $field = $this->field($type);
            $value = $dm->fieldKinds()->valueOf($field);
            $name = $type . 'x';
            $id = 'r' . ++$n;
            $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => $id, $name => $value->toStored($input, $field)]));

            $this->assertSame($output, $value->toOutput($dm->findType('all', $id)?->get($name), $field), "{$type} on {$storage}");
        }
    }

    public function test_json_keeps_empty_objects_and_lists_apart(): void
    {
        $value = $this->value('json');
        $field = $this->field('json');

        $this->assertSame('{}', json_encode($value->toOutput('{}', $field)));
        $this->assertSame('[]', json_encode($value->toOutput('[]', $field)));
        $this->assertSame('{"a":{}}', json_encode($value->toOutput('{"a":{}}', $field)));
        $this->assertSame('{"a":{"b":[]},"c":[{}]}', json_encode($value->toOutput('{"a":{"b":[]},"c":[{}]}', $field)));
        $this->assertSame(['a' => 1], $value->toOutput('{"a":1}', $field));
        // Input: text keeps its bytes; an empty list or an empty object is a value, not "no value".
        $this->assertSame('{}', $value->toStored('{}', $field));
        $this->assertSame('[]', $value->toStored([], $field));
        $this->assertSame('{}', $value->toStored(new \stdClass(), $field));
        $this->assertSame('{"a":{}}', $value->toStored('{"a":{}}', $field));
        $this->assertNull($value->toStored(null, $field));
        $this->assertNull($value->toStored('  ', $field));
    }

    public function test_json_text_reads_as_its_object(): void
    {
        $value = $this->value('json');
        $field = $this->field('json');

        $this->assertSame(['a' => 1, 'list' => [1, 2]], $value->toOutput('{"a":1,"list":[1,2]}', $field));
        $this->assertSame([1, 2], $value->toOutput(" [1,2]\n", $field));
        $this->assertSame('text', $value->toOutput('"text"', $field));
        $this->assertNull($value->toOutput('{not json', $field));
        $this->assertNull($value->toOutput('', $field));
        $this->assertNull($value->toOutput(null, $field));
        // An object the stored text is read from, written as text: the editor's layout is kept.
        $this->assertSame("{\n  \"b\": 1\n}", $value->toStored("  {\n  \"b\": 1\n}\n", $field));
    }

    /** @return iterable<string, array{string, mixed, string, string}> type, input, field, message */
    public static function wrongShapes(): iterable
    {
        yield 'email' => ['email', 'not an address', 'email', 'Must be a valid email address.'];
        yield 'phone' => ['phone', ['as_typed' => 'call me', 'e164' => null], 'phone', 'Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.'];
        yield 'color' => ['color', 'red', 'color', 'Must be a colour in hex form, such as #1a2b3c (or #1a2b3c80 with transparency).'];
        yield 'json' => ['json', '{a: 1}', 'json', 'Must be valid JSON, such as {"name": "value"} or [1, 2, 3].'];
    }

    #[DataProvider('wrongShapes')]
    public function test_wrong_shape_passes_through_for_the_rule(string $type, mixed $input, string $field, string $message): void
    {
        $value = $this->value($type);
        $stored = $value->toStored($input, $this->field($type));
        $this->assertSame($input, $stored);

        $dm = $this->dm('json');
        try {
            $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get('all'), ['uuid' => 'bad', $field . 'x' => $stored]));
            $this->fail('saved a value of the wrong shape');
        } catch (ValidationException $e) {
            $this->assertSame([$field . 'x' => [$message]], $e->errors());
        }
        $this->assertNull($dm->findType('all', 'bad'));
    }

    public function test_structured_values_of_the_wrong_shape_pass_through_as_their_object(): void
    {
        $badLink = ['kind' => 'teleport', 'target' => 'x', 'label' => null, 'new_tab' => false];
        $this->assertSame($badLink, $this->value('link')->toStored(json_encode($badLink), $this->field('link')));
        $this->assertNull($this->value('link')->toOutput($badLink, $this->field('link')));

        $place = ['lat' => 123.0, 'lng' => 8.0];
        $this->assertSame($place, $this->value('location')->toStored($place, $this->field('location')));
        $this->assertSame('25:99', $this->value('time')->toStored('25:99', $this->field('time')));
        $this->assertSame('tomorrow', $this->value('datetime')->toStored(' tomorrow ', $this->field('datetime')));
        $this->assertSame(['start' => '2026-03-05', 'end' => null], $this->value('daterange')->toStored(['start' => '2026-03-05'], $this->field('daterange')));
        foreach ([
            'daterange' => [['from' => '2026-03-01', 'to' => '2026-03-05'], ['start' => 20260301]],
            'link' => [['target' => 'x'], ['href' => 'https://example.org']],
            'phone' => [['number' => '044 123 45 67']],
            'embed' => [['url' => 'https://nowhere.example/v'], ['video' => 'x']],
            'table' => [['cells' => [['a']]]],
            'location' => [['latitude' => 1, 'longitude' => 2]],
        ] as $type => $inputs) {
            foreach ($inputs as $input) {
                $this->assertSame($input, $this->value($type)->toStored($input, $this->field($type)), $type . ' ' . json_encode($input));
                $this->assertSame($input, $this->value($type)->toStored(json_encode($input), $this->field($type)), $type . ' as text');
            }
        }
        $this->assertNull($this->value('daterange')->toStored(['start' => '', 'end' => ' '], $this->field('daterange')));
        $this->assertSame('not a video', $this->value('embed')->toStored('not a video', $this->field('embed')));
        $this->assertSame(['rows' => 'x'], $this->value('table')->toStored(['rows' => 'x'], $this->field('table')));
    }

    public function test_no_value_is_null(): void
    {
        foreach (self::TYPES as $type) {
            $value = $this->value($type);
            $field = $this->field($type);
            // email keeps blank text as "" (as a string field, and as Folio's form stored it in 0.92): test_blank_email_is_empty_text.
            foreach ($type === 'code' ? [null, '', []] : ($type === 'json' ? [null, '', '  '] : ($type === 'email' ? [null, []] : [null, '', '  ', []])) as $input) {
                $this->assertNull($value->toStored($input, $field), $type);
            }
            $this->assertNull($value->toOutput(null, $field), $type);
            $this->assertNull($value->toOutput('', $field), $type);
        }
    }

    /** @since 0.93.0 as Folio's form reads it: a national number in the field's "default_region", trimmed. */
    public function test_phone_reads_a_national_number_in_the_fields_default_region(): void
    {
        $value = $this->value('phone');
        $field = new TypeFieldDefinition('phone', 'phone', config: ['default_region' => 'CH']);

        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], $value->toStored(' 044 123 45 67 ', $field));
        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => null], $value->toStored('044 123 45 67', $this->field('phone')), 'without a region: no E.164 form');
        $this->assertSame(['as_typed' => '+33 1 23 45 67 89', 'e164' => '+33123456789'], $value->toStored('+33 1 23 45 67 89', $field), 'an international number is its own');
        // 0.92 stored values pass through unchanged, so a re-save changes no bytes.
        $stored = ['as_typed' => '044 123 45 67', 'e164' => null];
        $this->assertSame($stored, $value->toStored($stored, $field));
        $this->assertSame($stored, $value->toStored(json_encode($stored), $field));
    }

    /** @since 0.93.0 as Folio's form stores it: line ends as "\n", blank code is no value. */
    public function test_code_normalises_line_ends_and_blank_is_null(): void
    {
        $value = $this->value('code');
        $field = $this->field('code');

        $this->assertSame("a\nb\nc", $value->toStored("a\r\nb\rc", $field));
        $this->assertNull($value->toStored(" \n\t ", $field));
        $this->assertSame("\tindented\n", $value->toStored("\tindented\n", $field), 'code is not trimmed: 0.92 stored code passes through unchanged');
    }

    public function test_blank_email_is_empty_text(): void
    {
        // A text field: blank text is "" as for a string field (Folio's form stored "" in 0.92, so a
        // record it saved keeps its bytes); output reads "" as no value.
        $value = $this->value('email');
        $field = $this->field('email');
        $this->assertSame('', $value->toStored('', $field));
        $this->assertSame('', $value->toStored("  \n", $field));
        $this->assertNull($value->toOutput('', $field));
    }

    public function test_link_and_embed_list_references(): void
    {
        $link = $this->value('link');
        $this->assertInstanceOf(ListsReferences::class, $link);
        $record = json_encode(['kind' => 'record', 'target' => ['type' => 'page', 'id' => 'p1'], 'label' => null, 'new_tab' => false]);
        $this->assertSame(['page' => ['p1']], $link->references($record, $this->field('link')));
        $this->assertSame([], $link->references(['kind' => 'url', 'target' => 'https://example.org', 'label' => null, 'new_tab' => false], $this->field('link')));
        $this->assertSame([], $link->references(null, $this->field('link')));

        $embed = $this->value('embed');
        $this->assertInstanceOf(ListsReferences::class, $embed);
        $withPoster = ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'url' => self::VIDEO, 'poster' => 'asset-9'];
        $this->assertSame(['asset' => ['asset-9']], $embed->references(json_encode($withPoster), $this->field('embed')));
        $this->assertSame([], $embed->references(self::VIDEO, $this->field('embed')));
        $this->assertSame([], $embed->references(null, $this->field('embed')));

        foreach (array_diff(self::TYPES, ['link', 'embed']) as $type) {
            $this->assertNotInstanceOf(ListsReferences::class, $this->value($type), $type);
        }
    }

    private function field(string $type): TypeFieldDefinition
    {
        return new TypeFieldDefinition($type . 'x', $type);
    }

    private function value(string $type): FieldValue
    {
        $kinds = new FieldKinds();
        FieldsServiceProvider::registerKinds($kinds);

        return $kinds->valueOf($this->field($type));
    }

    private function dm(string $storage): DataManager
    {
        $fields = [];
        foreach (self::TYPES as $type) {
            $fields[$type . 'x'] = ['type' => $type];
        }
        file_put_contents($this->dir . '/models/all.json', (string) json_encode([
            'key' => 'all', 'table' => 'allfields', 'storage' => $storage, 'id_field' => 'uuid', 'fields' => $fields,
        ]));
        $driver = $storage === 'json'
            ? new JsonFileDriver($this->dir . '/data')
            : new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite'));
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models'), validatorFactory: new ValidatorFactory(new RuleFactory()));
        FieldsServiceProvider::registerKinds($dm->fieldKinds());
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('all'));
        }

        return $dm;
    }
}
