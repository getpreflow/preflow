<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

require_once __DIR__ . '/IdKindScenarios.php';

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\IdKind;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\StatesIdKinds;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\PlainDriver;

#[Entity(table: 'n')]
class NullableStringIdModel extends Model
{
    #[Id]
    public ?string $uuid = null;
}

#[Entity(table: 'n')]
class UntypedIdModel extends Model
{
    #[Id]
    public $id = 0;
}

#[Entity(table: 'n')]
class UnionIdModel extends Model
{
    #[Id]
    public int|string $id = 0;
}

#[Entity(table: 'n')]
class NoIdPropertyModel extends Model
{
    public string $label = '';
}

/** A storage that numbers every record itself and says so (StatesIdKinds). */
final class NumberingDriver implements StorageDriver, StatesIdKinds
{
    public function __construct(private StorageDriver $inner) {}

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        return $this->inner->findOne($type, $id, $idField);
    }

    public function findMany(string $type, \Preflow\Data\Query $query): \Preflow\Data\ResultSet
    {
        return $this->inner->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->inner->save($type, $id, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        return $this->inner->exists($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }

    public function idKindOf(TypeDefinition $def): ?IdKind
    {
        return $def->key === 'stated' ? IdKind::Int : null;
    }
}

/** A type's id kind on SQLite and JSON files (MysqlIdKindTest has MySQL), and what a storage states. */
final class IdKindTest extends TestCase
{
    use IdKindScenarios;

    private string $dir;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_id_kind_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/json', 0755, true);
        mkdir($this->dir . '/types', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $remove($path . '/' . $entry);
                    }
                }
                rmdir($path);
            } elseif (file_exists($path)) {
                unlink($path);
            }
        };
        $remove($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function kinds(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'json' => ['json'];
    }

    protected function sql(string $kind): bool
    {
        return $kind === 'sqlite';
    }

    protected function generatesIds(string $kind): bool
    {
        return $kind !== 'json';
    }

    protected function manager(string $kind): DataManager
    {
        return new DataManager(['default' => $this->driver($kind)], 'default', $this->registry());
    }

    protected function table(string $kind, string $table, bool $intKey, ?string $key = null): void
    {
        if ($kind === 'sqlite') {
            $key ??= $intKey ? 'id' : 'uuid';
            $this->pdo?->exec("CREATE TABLE {$table} ({$key} " . ($intKey ? 'INTEGER' : 'TEXT') . ' PRIMARY KEY, label TEXT)');
        }
    }

    private function registry(): TypeRegistry
    {
        return new TypeRegistry($this->dir . '/types', new Locales(['en'], 'en'), [
            'kind_string_rows' => StringIdKindRow::class,
            'kind_int_rows' => IntIdKindRow::class,
        ]);
    }

    private function driver(string $kind): StorageDriver
    {
        if ($kind === 'json') {
            return new JsonFileDriver($this->dir . '/json');
        }
        $this->pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return new SqliteDriver($this->pdo);
    }

    /** The one rule for a model class, as DataManager gives a new record its id: a string property is a string id, anything else a number. */
    public function test_a_model_class_states_its_id_kind_by_the_id_property(): void
    {
        $this->assertSame(IdKind::String, IdKind::ofModel(StringIdKindRow::class, 'uuid'));
        $this->assertSame(IdKind::String, IdKind::ofModel(NullableStringIdModel::class, 'uuid'));
        $this->assertSame(IdKind::Int, IdKind::ofModel(IntIdKindRow::class, 'id'));
        $this->assertSame(IdKind::Int, IdKind::ofModel(TallyRow::class, 'id'));
        $this->assertSame(IdKind::Int, IdKind::ofModel(UntypedIdModel::class, 'id'));
        $this->assertSame(IdKind::Int, IdKind::ofModel(UnionIdModel::class, 'id'));
        $this->assertSame(IdKind::Int, IdKind::ofModel(NoIdPropertyModel::class, 'uuid'));
        $this->assertSame(['string', 'int'], array_map(static fn (IdKind $k): string => $k->value, IdKind::cases()));
    }

    /** A type built outside the registry states no kind; one with a non-number id field is a string id. */
    public function test_a_type_built_by_hand_follows_its_declaration(): void
    {
        $dm = new DataManager(['default' => new JsonFileDriver($this->dir . '/json')]);
        $field = static fn (string $type): \Preflow\Data\TypeFieldDefinition => new \Preflow\Data\TypeFieldDefinition('uuid', $type);

        $this->assertSame(IdKind::String, $dm->idKind(new TypeDefinition('a', 'a', 'default', [])));
        $this->assertSame(IdKind::String, $dm->idKind(new TypeDefinition('a', 'a', 'default', ['uuid' => $field('date')])));
        $this->assertSame(IdKind::Int, $dm->idKind(new TypeDefinition('a', 'a', 'default', ['uuid' => $field('number')])));
        $this->assertSame(IdKind::Int, $dm->idKind(new TypeDefinition('a', 'a', 'default', [], idKind: IdKind::Int)));
    }

    /** A field type an extension stores as a number (FieldKinds::column()) makes a number id, as the storage writes it. */
    public function test_an_extension_number_field_type_makes_an_int_id(): void
    {
        $dm = new DataManager(['default' => new JsonFileDriver($this->dir . '/json')]);
        $def = new TypeDefinition('a', 'a', 'default', ['id' => new \Preflow\Data\TypeFieldDefinition('id', 'integer')], idField: 'id');

        $this->assertSame(IdKind::String, $dm->idKind($def), 'unknown to the data layer: stored as text');
        $dm->fieldKinds()->column('integer', \Preflow\Data\Schema\ColumnKind::Number);
        $this->assertSame(IdKind::Int, $dm->idKind($def));
    }

    /**
     * A SQL driver reads the kind from the id column (StatesIdKinds), classified by its grammar
     * (ClassifiesIdColumns; IdColumnClassificationTest has the types): no column or no table is
     * nothing, so the type decides. Remembered per table until forgetColumns(); a missing table is read again.
     */
    public function test_a_sql_driver_reads_the_id_kind_from_the_key_column(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $driver = new SqliteDriver($pdo);
        $this->assertInstanceOf(StatesIdKinds::class, $driver);
        $this->assertInstanceOf(\Preflow\Data\RemembersColumns::class, $driver);
        $kinds = [];
        foreach (['INTEGER' => IdKind::Int, 'BIGINT' => IdKind::Int, 'INT8' => IdKind::Int, 'TEXT' => IdKind::String, 'VARCHAR(64)' => IdKind::String, 'NUMERIC' => null, 'BLOB' => null] as $type => $expected) {
            $table = 't' . count($kinds);
            $pdo->exec("CREATE TABLE {$table} (k {$type} PRIMARY KEY, label TEXT)");
            $kinds[$type] = [$expected, $driver->idKindOf(new TypeDefinition($table, $table, 'default', [], idField: 'k'))];
        }
        foreach ($kinds as $type => [$expected, $actual]) {
            $this->assertSame($expected, $actual, $type);
        }
        $this->assertNull($driver->idKindOf(new TypeDefinition('t0', 't0', 'default', [], idField: 'missing')), 'no such column');
        $this->assertNull($driver->idKindOf(new TypeDefinition('absent', 'absent', 'default', [])), 'no table');

        $absent = new TypeDefinition('absent', 'absent', 'default', []);
        $pdo->exec('CREATE TABLE absent (uuid INTEGER PRIMARY KEY)');
        $this->assertSame(IdKind::Int, $driver->idKindOf($absent), 'a missing table is not remembered');
        $pdo->exec('DROP TABLE absent');
        $pdo->exec('CREATE TABLE absent (uuid TEXT PRIMARY KEY)');
        $this->assertSame(IdKind::Int, $driver->idKindOf($absent), 'remembered');
        $driver->forgetColumns('t0');
        $this->assertSame(IdKind::Int, $driver->idKindOf($absent), 'another table forgotten');
        $driver->forgetColumns('absent');
        $this->assertSame(IdKind::String, $driver->idKindOf($absent));
        $pdo->exec('DROP TABLE absent');
        $pdo->exec('CREATE TABLE absent (uuid BIGINT PRIMARY KEY)');
        $driver->forgetColumns();
        $this->assertSame(IdKind::Int, $driver->idKindOf($absent), 'every table forgotten');
    }

    /**
     * A storage that holds its own kind of id says so (StatesIdKinds) and is asked before the
     * declaration; a model class still decides for its type, as its id property holds only that kind.
     */
    public function test_a_storage_states_its_own_id_kind(): void
    {
        $registry = $this->registry();
        $dm = new DataManager(['default' => new NumberingDriver(new JsonFileDriver($this->dir . '/json'))], 'default', $registry);
        $stated = $registry->define(['key' => 'stated', 'table' => 'stated', 'fields' => ['uuid' => ['type' => 'string']]]);
        $other = $registry->define(['key' => 'other', 'table' => 'other', 'fields' => []]);

        $this->assertSame(IdKind::Int, $dm->idKind($stated), 'the storage says');
        $this->assertSame(IdKind::String, $dm->idKind($other), 'the storage leaves it to the type');
        $this->assertSame(IdKind::String, $dm->idKind($registry->get('kind_string_rows')), 'the class decides');

        $plain = new DataManager(['default' => new PlainDriver(new NumberingDriver(new JsonFileDriver($this->dir . '/json')))], 'default', $registry);
        $this->assertSame(IdKind::String, $plain->idKind($stated), 'a storage that does not say leaves it to the type');
    }
}
