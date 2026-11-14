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
use Preflow\Data\Tests\Fixtures\ModelBridge\TaggedReference;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

/**
 * A string list a field type stores as JSON text (Folio's tags field, 0.77) reads into a class
 * model's `?array` property, on SQL and JSON storage; the model's own save writes a list the
 * dynamic side reads back; a legacy comma string is an UnreadableValue error, never a silent null.
 */
final class TagListHydrationTest extends TestCase
{
    private string $dir;

    private DataManager $dm;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/ModelBridge/TaggedReference.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tag_lists_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
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
            $pdo->exec('CREATE TABLE reference (uuid TEXT PRIMARY KEY, name TEXT, uses TEXT, _version INTEGER)');
            $driver = new SqliteDriver($pdo);
        } else {
            $driver = new JsonFileDriver($this->dir . '/store');
        }
        $types = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'), ['reference' => TaggedReference::class]);
        $this->dm = new DataManager(['default' => $driver], 'default', $types, new ValidatorFactory(new RuleFactory()));
    }

    #[DataProvider('storages')]
    public function test_the_json_list_a_field_type_writes_hydrates_the_array_property(string $storage): void
    {
        $this->boot($storage);
        $def = $this->dm->getTypeRegistry()->get('reference');
        $this->dm->saveType(new DynamicRecord($def, ['uuid' => 'r1', 'name' => 'Atlas', 'uses' => '["Folio","Übersetzung"]']));

        $this->assertSame(['Folio', 'Übersetzung'], $this->dm->find(TaggedReference::class, 'r1')?->uses);
    }

    #[DataProvider('storages')]
    public function test_the_model_s_own_save_reads_back_on_both_sides(string $storage): void
    {
        $this->boot($storage);
        $ref = new TaggedReference();
        $ref->uuid = 'r1';
        $ref->name = 'Atlas';
        $ref->uses = ['Folio', 'Mitglieder'];
        $this->dm->save($ref);

        $this->assertSame(['Folio', 'Mitglieder'], $this->dm->find(TaggedReference::class, 'r1')?->uses);
        $stored = $this->dm->findType('reference', 'r1')?->get('uses');
        $this->assertSame(['Folio', 'Mitglieder'], is_string($stored) ? json_decode($stored, true) : $stored);
    }

    #[DataProvider('storages')]
    public function test_a_legacy_comma_string_is_an_error_naming_type_field_and_record(string $storage): void
    {
        $this->boot($storage);
        $this->dm->driver('default')->save('reference', 'r1', ['uuid' => 'r1', 'name' => 'Atlas', 'uses' => 'Folio, members'], 'uuid');

        $this->expectException(\Preflow\Data\UnreadableValue::class);
        $this->expectExceptionMessage('Type "reference", record "r1": field "uses" holds a value that is not a JSON list, so ' . TaggedReference::class . '::$uses (array) cannot take it.');
        $this->dm->find(TaggedReference::class, 'r1');
    }

    #[DataProvider('storages')]
    public function test_an_empty_or_null_value_reads_as_null(string $storage): void
    {
        $this->boot($storage);
        $this->dm->driver('default')->save('reference', 'r1', ['uuid' => 'r1', 'name' => 'Atlas', 'uses' => null], 'uuid');
        $this->dm->driver('default')->save('reference', 'r2', ['uuid' => 'r2', 'name' => 'Atlas', 'uses' => ''], 'uuid');

        $this->assertNull($this->dm->find(TaggedReference::class, 'r1')?->uses);
        $this->assertNull($this->dm->find(TaggedReference::class, 'r2')?->uses);
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
