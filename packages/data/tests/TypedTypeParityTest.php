<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Tests\Fixtures\ModelBridge\Vacancy;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/** Typed reads and writes of a class-backed type do what the same JSON type does, on SQL and JSON storage. */
final class TypedTypeParityTest extends TestCase
{
    private string $dir;

    private DataManager $dm;

    private TypeRegistry $types;

    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map this package's test namespace.
        require_once __DIR__ . '/Fixtures/ModelBridge/Vacancy.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typed_parity_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/company.json', json_encode([
            'key' => 'company', 'table' => 'company', 'fields' => ['name' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
    }

    private function boot(string $storage): void
    {
        if ($storage === 'sqlite') {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE vacancy (uuid TEXT PRIMARY KEY, title TEXT, company TEXT, salary_from INTEGER, open INTEGER, code TEXT UNIQUE, _version INTEGER, _publish_at TEXT)');
            $pdo->exec('CREATE TABLE company (uuid TEXT PRIMARY KEY, name TEXT)');
            $driver = new SqliteDriver($pdo);
        } else {
            $driver = new JsonFileDriver($this->dir . '/store');
        }
        $this->types = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'), ['vacancy' => Vacancy::class]);
        $this->dm = new DataManager(['default' => $driver], 'default', $this->types, new ValidatorFactory(new RuleFactory()));
    }

    private function vacancy(array $values): Vacancy
    {
        $vacancy = new Vacancy();
        foreach ($values as $name => $value) {
            $vacancy->{$name} = $value;
        }

        return $vacancy;
    }

    #[DataProvider('storages')]
    public function test_insert_generates_the_id_folio_would(string $storage): void
    {
        $this->boot($storage);
        $saved = $this->vacancy(['title' => ['en' => 'Carpenter'], 'company' => 'c1']);
        $this->dm->save($saved);
        $inserted = $this->vacancy(['title' => ['en' => 'Joiner'], 'company' => 'c1']);
        $this->dm->insert($inserted);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $saved->uuid);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $inserted->uuid);
        $this->assertNotSame($saved->uuid, $inserted->uuid);
        $this->assertSame(['en' => 'Joiner'], $this->dm->find(Vacancy::class, $inserted->uuid)?->title);
    }

    #[DataProvider('storages')]
    public function test_a_typed_save_leaves_folios_columns_alone(string $storage): void
    {
        $this->boot($storage);
        $record = DynamicRecord::fromArray($this->types->get('vacancy'), [
            'uuid' => 'a1', 'title' => ['en' => 'Old'], 'company' => 'c1', '_version' => 3, '_publish_at' => '2026-12-01 08:00:00',
        ]);
        $this->dm->saveType($record, validate: false);

        $vacancy = $this->dm->find(Vacancy::class, 'a1');
        $vacancy->title = ['en' => 'New'];
        $this->dm->save($vacancy);

        $stored = $this->dm->findType('vacancy', 'a1');
        $this->assertSame('{"en":"New"}', is_array($stored->get('title')) ? json_encode($stored->get('title')) : $stored->get('title'));
        $this->assertSame(3, (int) $stored->get('_version'));
        $this->assertSame('2026-12-01 08:00:00', $stored->get('_publish_at'));
    }

    #[DataProvider('storages')]
    public function test_a_typed_save_stores_what_save_type_stores(string $storage): void
    {
        $this->boot($storage);
        $values = ['title' => ['en' => 'Carpenter', 'de' => 'Schreiner'], 'company' => 'c1', 'salary_from' => 4200, 'open' => true, 'code' => 'C-1'];
        $this->dm->save($this->vacancy(['uuid' => 'a'] + $values + ['scratch' => 'not a column']));
        $this->dm->saveType(DynamicRecord::fromArray($this->types->get('vacancy'), ['uuid' => 'b'] + array_replace($values, ['code' => 'C-2'])));

        $driver = $this->dm->driver('default');
        $typed = $driver->findOne('vacancy', 'a');
        $dynamic = $driver->findOne('vacancy', 'b');
        $this->assertArrayNotHasKey('scratch', $typed, 'a property that is not a field is not a column');
        unset($typed['uuid'], $dynamic['uuid'], $typed['code'], $dynamic['code']);
        $this->assertSame($dynamic, $typed);
        if ($storage === 'sqlite') {
            $this->assertSame('{"en":"Carpenter","de":"Schreiner"}', $typed['title'], 'a locale map is JSON text on SQL');
        }
    }

    #[DataProvider('storages')]
    public function test_a_typed_read_gives_the_property_types(string $storage): void
    {
        $this->boot($storage);
        // As Folio stores a form's values: numbers and yes/no as text on JSON storage.
        $this->dm->saveType(DynamicRecord::fromArray($this->types->get('vacancy'), [
            'uuid' => 'a1', 'title' => ['en' => 'Carpenter', 'de' => 'Schreiner'], 'company' => 'c1', 'salary_from' => '4200', 'open' => '1',
        ]), validate: false);

        $vacancy = $this->dm->find(Vacancy::class, 'a1');

        $this->assertSame(['en' => 'Carpenter', 'de' => 'Schreiner'], $vacancy->title);
        $this->assertSame(4200, $vacancy->salary_from);
        $this->assertTrue($vacancy->open);
        $this->assertSame('c1', $vacancy->company, 'a relation-only property reads');
    }

    #[DataProvider('storages')]
    public function test_a_typed_query_compares_translatable_fields_in_the_locale(string $storage): void
    {
        $this->boot($storage);
        $this->dm->save($this->vacancy(['uuid' => 'a1', 'title' => ['en' => 'Carpenter', 'de' => 'Schreiner'], 'company' => 'c1']));
        $this->dm->save($this->vacancy(['uuid' => 'a2', 'title' => ['en' => 'Schreiner'], 'company' => 'c1']));

        $found = $this->dm->query(Vacancy::class)->locale('de')->where('title', 'Schreiner')->get()->items();

        $this->assertCount(1, $found);
        $this->assertInstanceOf(Vacancy::class, $found[0]);
        $this->assertSame('a1', $found[0]->uuid);
        $this->assertSame('a2', $this->dm->query(Vacancy::class)->where('title', 'Schreiner')->first()?->uuid, 'without a locale: the default one');
    }

    #[DataProvider('storages')]
    public function test_a_typed_loose_search_reaches_related_records(string $storage): void
    {
        $this->boot($storage);
        $this->dm->saveType(DynamicRecord::fromArray($this->types->get('company'), ['uuid' => 'c1', 'name' => 'Acme Ltd']));
        $this->dm->saveType(DynamicRecord::fromArray($this->types->get('company'), ['uuid' => 'c2', 'name' => 'Other']));
        $this->dm->save($this->vacancy(['uuid' => 'a1', 'title' => ['en' => 'Carpenter'], 'company' => 'c1']));
        $this->dm->save($this->vacancy(['uuid' => 'a2', 'title' => ['en' => 'Joiner'], 'company' => 'c2']));

        $typed = array_map(static fn (Vacancy $v) => $v->uuid, $this->dm->query(Vacancy::class)->searchLoose('acme')->get()->items());
        $dynamic = array_map(static fn (DynamicRecord $r) => $r->getId(), $this->dm->queryType('vacancy')->searchLoose('acme')->get()->items());

        $this->assertSame(['a1'], $typed);
        $this->assertSame($dynamic, $typed);
    }

    #[DataProvider('storages')]
    public function test_a_relation_only_property_validates_like_the_json_relation(string $storage): void
    {
        $this->boot($storage);
        $typed = $this->failures(fn () => $this->dm->save($this->vacancy(['title' => ['en' => 'Carpenter']])));
        $dynamic = $this->failures(fn () => $this->dm->saveType(DynamicRecord::fromArray($this->types->get('vacancy'), ['uuid' => 'x', 'title' => ['en' => 'Carpenter']])));

        $this->assertSame(['company'], array_keys($typed));
        $this->assertSame($dynamic, $typed);
    }

    #[DataProvider('storages')]
    public function test_a_translatable_field_validates_per_locale_like_the_type(string $storage): void
    {
        $this->boot($storage);
        $typed = $this->failures(fn () => $this->dm->save($this->vacancy(['title' => ['de' => 'Schreiner'], 'company' => 'c1'])));

        $this->assertSame(['title'], array_keys($typed), 'required in the default locale');
    }

    #[DataProvider('storages')]
    public function test_a_unique_field_is_unique_as_in_the_type(string $storage): void
    {
        $this->boot($storage);
        $this->dm->saveType(DynamicRecord::fromArray($this->types->get('vacancy'), ['uuid' => 'a1', 'title' => ['en' => 'A'], 'company' => 'c1', 'code' => 'C-1']));

        $validated = $this->failures(fn () => $this->dm->save($this->vacancy(['title' => ['en' => 'B'], 'company' => 'c1', 'code' => 'C-1'])));
        $guarded = $this->failures(fn () => $this->dm->save($this->vacancy(['title' => ['en' => 'B'], 'company' => 'c1', 'code' => 'C-1']), validate: false));

        $this->assertSame(['code'], array_keys($validated));
        $this->assertSame(['code'], array_keys($guarded), 'the storage refuses it without validation too');
        $this->assertCount(1, $this->dm->queryType('vacancy')->get()->items(), 'nothing saved');
    }

    /** @return array<string, list<string>> */
    private function failures(\Closure $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors();
        }
        $this->fail('expected a ValidationException');
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
