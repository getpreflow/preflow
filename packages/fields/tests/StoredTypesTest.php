<?php

declare(strict_types=1);

namespace Preflow\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeRegistry;
use Preflow\Fields\FieldsServiceProvider;
use Preflow\Fields\Values\Code;
use Preflow\Fields\Values\Color;
use Preflow\Fields\Values\JsonText;
use Preflow\Fields\Values\Phone;
use Preflow\Testing\MysqlTestDatabase;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * The email, phone, colour, code and JSON fields on every storage (0.91): each type's column, its
 * value read back as saved (through its own fromStored()), the checks on save without Folio, and
 * which of them loose search reaches.
 */
final class StoredTypesTest extends TestCase
{
    private string $dir;

    private bool $mysql = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fields_types_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->mysql) {
            MysqlTestDatabase::dropAllTables(MysqlTestDatabase::pdo());
        }
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

    #[DataProvider('storages')]
    public function test_every_type_round_trips(string $storage): void
    {
        $this->roundTrip($this->dm($storage));
    }

    #[Group('mysql')]
    public function test_every_type_round_trips_on_mysql(): void
    {
        $this->roundTrip($this->dm('mysql'));
    }

    private function roundTrip(DataManager $dm): void
    {
        $def = $dm->getTypeRegistry()->get('contact');
        $phone = Phone::of('044 123 45 67', 'CH');
        $code = new Code("<?php\necho \"ä € 😀\";", 'php');
        $json = "{\n  \"b\": 1,\n  \"a\": [\"ä\", null]\n}";
        $dm->saveType(DynamicRecord::fromArray($def, [
            'uuid' => 'c1', 'name' => 'Full', 'email' => 'team@example.org', 'phone' => $phone->toStored(),
            'brand' => '#c00000', 'tint' => '#c0000080', 'snippet' => $code->toStored(), 'settings' => $json,
        ]));
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'c2', 'name' => 'Empty', 'email' => null, 'phone' => null, 'brand' => null, 'snippet' => null, 'settings' => null]));

        $full = $dm->findType('contact', 'c1');
        $this->assertSame('team@example.org', $full?->get('email'));
        $this->assertEquals($phone, Phone::fromStored($full?->get('phone')));
        $this->assertSame(['as_typed' => '044 123 45 67', 'e164' => '+41441234567'], Phone::fromStored($full?->get('phone'))?->toStored());
        $this->assertSame('#c00000', Color::fromStored($full?->get('brand'))?->toStored());
        $this->assertSame('#c0000080', Color::fromStored($full?->get('tint'))?->toStored());
        $this->assertEquals($code, Code::fromStored($full?->get('snippet')));
        $this->assertSame($json, JsonText::fromStored($full?->get('settings')), 'JSON text kept as written');
        $this->assertSame(['b' => 1, 'a' => ['ä', null]], JsonText::decode($full?->get('settings')));

        $empty = $dm->findType('contact', 'c2');
        $this->assertNull(Phone::fromStored($empty?->get('phone')));
        $this->assertNull(Code::fromStored($empty?->get('snippet')));
        $this->assertNull(JsonText::fromStored($empty?->get('settings')));
    }

    #[DataProvider('storages')]
    public function test_a_save_refuses_each_type_on_its_field_without_folio(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('contact');
        $save = static fn (array $values) => $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'bad', 'name' => 'x'] + $values));

        foreach ([
            ['email', 'not an address', 'Must be a valid email address.'],
            ['mobile', ['as_typed' => '044 123 45 67', 'e164' => null], 'Cannot tell which country this number belongs to: start it with + and the country code, e.g. +41 44 123 45 67.'],
            ['phone', ['as_typed' => 'call me', 'e164' => null], 'Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.'],
            ['brand', 'red', 'Must be a colour in hex form, such as #1a2b3c (or #1a2b3c80 with transparency).'],
            ['snippet', ['code' => 'x', 'language' => 'a b'], 'The language must be a short name such as php or javascript (letters, digits and + # . _ -).'],
            ['settings', '{a: 1}', 'Must be valid JSON, such as {"name": "value"} or [1, 2, 3].'],
        ] as [$field, $value, $message]) {
            try {
                $save([$field => $value]);
                $this->fail("saved {$field}");
            } catch (ValidationException $e) {
                $this->assertSame([$field => [$message]], $e->errors(), $field);
            }
        }
        $this->assertNull($dm->findType('contact', 'bad'));
    }

    #[DataProvider('storages')]
    public function test_a_palette_only_colour_refuses_other_colours(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('contact');
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'ok', 'name' => 'x', 'accent' => '#0a5ca8']));
        try {
            $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'no', 'name' => 'x', 'accent' => '#123456']));
            $this->fail('saved a colour outside the palette');
        } catch (ValidationException $e) {
            $this->assertSame(['accent' => ['Choose one of the colours offered: #c00000, #0a5ca8.']], $e->errors());
        }
    }

    public function test_columns(): void
    {
        $dm = $this->dm('json');
        $fields = $dm->getTypeRegistry()->get('contact')->fields;
        $kind = static fn (string $name): ColumnKind => $dm->fieldKinds()->columnKindOf($fields[$name]);

        $this->assertSame(ColumnKind::Text, $kind('email'), 'a short column only when unique or indexed');
        $this->assertSame(ColumnKind::Short, $dm->fieldKinds()->columnKindOf($fields['email']->withIndex()));
        $this->assertSame(ColumnKind::Text, $kind('phone'));
        $this->assertSame(ColumnKind::Text, $kind('snippet'));
        $this->assertSame(ColumnKind::Text, $kind('settings'));
        $this->assertSame(ColumnKind::Short, $dm->fieldKinds()->columnKindOf($fields['brand']->withIndex()));
    }

    #[DataProvider('storages')]
    public function test_loose_search_reaches_an_email_but_not_the_structured_types_or_json(string $storage): void
    {
        $dm = $this->dm($storage);
        $def = $dm->getTypeRegistry()->get('contact');
        $dm->saveType(DynamicRecord::fromArray($def, [
            'uuid' => 'c1', 'name' => 'Alpha', 'email' => 'findable@example.org', 'phone' => Phone::of('+41 44 123 45 67')->toStored(),
            'brand' => '#c00000', 'snippet' => ['code' => 'uniquecodeword', 'language' => 'php'], 'settings' => '{"jsonword": 1}',
        ]));
        $ids = static fn (string $term): array => array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $dm->queryType('contact')->searchLoose($term)->get()->items());

        $this->assertSame(['c1'], $ids('findable@example.org'), 'an email field is text');
        $this->assertSame([], $ids('uniquecodeword'), 'code is stored as an object: not searched, like a link');
        $this->assertSame([], $ids('jsonword'));
        $this->assertSame([], $ids('+41441234567'));
    }

    private function dm(string $storage): DataManager
    {
        file_put_contents($this->dir . '/models/contact.json', (string) json_encode([
            'key' => 'contact', 'table' => 'contact', 'storage' => $storage, 'id_field' => 'uuid',
            'fields' => [
                'name' => ['type' => 'string'],
                'email' => ['type' => 'email'],
                'phone' => ['type' => 'phone', 'default_region' => 'CH'],
                'mobile' => ['type' => 'phone'],
                'brand' => ['type' => 'color'],
                'tint' => ['type' => 'color'],
                'accent' => ['type' => 'color', 'palette' => ['#C00000', '#0a5ca8'], 'palette_only' => true],
                'snippet' => ['type' => 'code'],
                'settings' => ['type' => 'json'],
            ],
        ]));
        $driver = match ($storage) {
            'json' => new JsonFileDriver($this->dir . '/data'),
            'sqlite' => new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/app.sqlite')),
            'mysql' => new MysqlDriver(MysqlTestDatabase::pdo()),
        };
        $this->mysql = $storage === 'mysql';
        $dm = new DataManager([$storage => $driver], $storage, new TypeRegistry($this->dir . '/models'), validatorFactory: new ValidatorFactory(new RuleFactory()));
        FieldsServiceProvider::registerKinds($dm->fieldKinds());
        if ($storage !== 'json') {
            (new RecordTableSync($dm))->sync($dm->getTypeRegistry()->get('contact'));
        }

        return $dm;
    }
}
