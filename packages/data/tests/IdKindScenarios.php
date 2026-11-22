<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\IdKind;
use Preflow\Data\Model;
use Preflow\Data\Schema\RecordTableSync;

/** A class-backed type with a string id: the data layer gives a new record its id. */
#[Entity(table: 'kind_string_rows', storage: 'default', type: 'kind_string_rows')]
class StringIdKindRow extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public ?string $label = null;
}

/** A class-backed type with an int id: the storage numbers a new record. */
#[Entity(table: 'kind_int_rows', storage: 'default', type: 'kind_int_rows')]
class IntIdKindRow extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public ?string $label = null;
}

/**
 * The id kind of a type (DataManager::idKind(), since 0.82.0) is what its storage holds: a model
 * class's id property decides (string: the data layer gives a new record a string id; int: the
 * storage numbers it); else a SQL table's id column says (PdoDriver's StatesIdKinds: an integer
 * key a number, a text key a string); else a type has a string id unless it declares its id field
 * as a number field. The using test gives kinds() (a data provider), manager($kind) with the two
 * classes registered, table($kind, $table, $intKey) for SQL storage, generatesIds($kind) and
 * sql($kind) (whether the storage has tables).
 */
trait IdKindScenarios
{
    abstract protected function manager(string $kind): DataManager;

    /** Makes $table on SQL storage, its key an auto-numbered integer or text ($key: its name, else id or uuid); nothing on JSON. */
    abstract protected function table(string $kind, string $table, bool $intKey, ?string $key = null): void;

    abstract protected function generatesIds(string $kind): bool;

    abstract protected function sql(string $kind): bool;

    #[DataProvider('kinds')]
    public function test_an_undeclared_id_is_a_string(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_files', false);
        $def = $dm->getTypeRegistry()->define(['key' => 'kind_files', 'table' => 'kind_files', 'fields' => ['label' => ['type' => 'string']]]);

        $this->assertNull($def->idKind, 'no class states it');
        $this->assertSame(IdKind::String, $dm->idKind($def));
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'r-1', 'label' => 'One']));
        $this->assertSame('One', $dm->findType('kind_files', 'r-1')?->get('label'));
    }

    #[DataProvider('kinds')]
    public function test_a_declared_string_id_is_a_string(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_files', false);
        $def = $dm->getTypeRegistry()->define(['key' => 'kind_files', 'table' => 'kind_files', 'fields' => ['uuid' => ['type' => 'string'], 'label' => ['type' => 'string']]]);

        $this->assertSame(IdKind::String, $dm->idKind($def));
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'r-1', 'label' => 'One']));
        $this->assertSame('r-1', $dm->findType('kind_files', 'r-1')?->getId());
    }

    #[DataProvider('kinds')]
    public function test_a_declared_number_id_is_an_int(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_numbered', true);
        $def = $dm->getTypeRegistry()->define(['key' => 'kind_numbered', 'table' => 'kind_numbered', 'id_field' => 'id', 'fields' => ['id' => ['type' => 'number'], 'label' => ['type' => 'string']]]);

        $this->assertNull($def->idKind);
        $this->assertSame(IdKind::Int, $dm->idKind($def));
        $dm->saveType(DynamicRecord::fromArray($def, ['id' => '7', 'label' => 'Seven']));
        $this->assertSame(7, $dm->driver('default')->findOne('kind_numbered', 7, 'id')['id'] ?? null, 'stored as the number 7 (an integer key; JSON: the number field\'s value)');
        $this->assertSame('Seven', $dm->findType('kind_numbered', '7')?->get('label'));
    }

    /**
     * The table sync makes a type's key text whatever its declared id field says, so on SQL a
     * declared number id is a string id once the table is there; JSON files follow the declaration.
     * The answer is read again after the sync (RemembersColumns::forgetColumns()).
     */
    #[DataProvider('kinds')]
    public function test_a_synced_table_makes_a_declared_number_id_a_string(string $kind): void
    {
        $dm = $this->manager($kind);
        $def = $dm->getTypeRegistry()->define(['key' => 'kind_synced', 'table' => 'kind_synced', 'id_field' => 'id', 'fields' => ['id' => ['type' => 'number'], 'label' => ['type' => 'string']]]);

        $this->assertSame(IdKind::Int, $dm->idKind($def), 'no table yet: the declaration decides');
        (new RecordTableSync($dm))->sync($def);
        $this->assertSame($this->sql($kind) ? IdKind::String : IdKind::Int, $dm->idKind($def));
        $dm->saveType(DynamicRecord::fromArray($def, ['id' => '7', 'label' => 'Seven']));
        $this->assertSame($this->sql($kind) ? '7' : 7, $dm->driver('default')->findOne('kind_synced', '7', 'id')['id'] ?? null, 'a text key holds text');
    }

    /** An integer key makes an undeclared id a number id on SQL; JSON files have no key column. */
    #[DataProvider('kinds')]
    public function test_a_number_key_makes_an_undeclared_id_an_int(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_numbered', true);
        $def = $dm->getTypeRegistry()->define(['key' => 'kind_numbered', 'table' => 'kind_numbered', 'id_field' => 'id', 'fields' => ['label' => ['type' => 'string']]]);

        $this->assertSame($this->sql($kind) ? IdKind::Int : IdKind::String, $dm->idKind($def));
    }

    /** Without a table the declaration decides: a number field a number id, else a string id. */
    #[DataProvider('kinds')]
    public function test_without_a_table_the_declaration_decides(string $kind): void
    {
        $dm = $this->manager($kind);
        $registry = $dm->getTypeRegistry();
        $number = $registry->define(['key' => 'kind_none_number', 'table' => 'kind_none_number', 'id_field' => 'id', 'fields' => ['id' => ['type' => 'number']]]);
        $undeclared = $registry->define(['key' => 'kind_none', 'table' => 'kind_none', 'fields' => ['label' => ['type' => 'string']]]);

        $this->assertSame(IdKind::Int, $dm->idKind($number));
        $this->assertSame(IdKind::String, $dm->idKind($undeclared));
    }

    /** A model class whose id kind its table's key contradicts is refused, never answered either way. */
    #[DataProvider('kinds')]
    public function test_a_class_its_table_contradicts_is_refused(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_int_rows', false, 'id');
        $def = $dm->getTypeRegistry()->get('kind_int_rows');
        if (!$this->sql($kind)) {
            $this->assertSame(IdKind::Int, $dm->idKind($def), 'JSON files state nothing: the class decides');

            return;
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('kind_int_rows');
        $dm->idKind($def);
    }

    #[DataProvider('kinds')]
    public function test_a_class_with_a_string_id_is_a_string(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_string_rows', false);
        $def = $dm->getTypeRegistry()->get('kind_string_rows');

        $this->assertSame(IdKind::String, $def->idKind, 'the class states it');
        $this->assertSame(IdKind::String, $dm->idKind($def));
        $row = new StringIdKindRow();
        $row->label = 'One';
        $dm->insert($row);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row->uuid, 'the data layer gave it a string id');
        $this->assertSame('One', $dm->findType('kind_string_rows', $row->uuid)?->get('label'));
    }

    #[DataProvider('kinds')]
    public function test_a_class_with_an_int_id_is_an_int(string $kind): void
    {
        $dm = $this->manager($kind);
        $this->table($kind, 'kind_int_rows', true);
        $def = $dm->getTypeRegistry()->get('kind_int_rows');

        $this->assertSame(IdKind::Int, $def->idKind, 'the class states it');
        $this->assertSame(IdKind::Int, $dm->idKind($def));
        $row = new IntIdKindRow();
        $row->label = 'One';
        if (!$this->generatesIds($kind)) {
            $row->id = 5; // JSON files number nothing: the caller gives the number
        }
        $dm->insert($row);
        $this->assertGreaterThan(0, $row->id, 'a number id');
        $this->assertSame('One', $dm->findType('kind_int_rows', (string) $row->id)?->get('label'));
    }
}
