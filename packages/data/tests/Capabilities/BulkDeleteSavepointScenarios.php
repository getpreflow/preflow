<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Capabilities;

use PHPUnit\Framework\Attributes\DataProvider;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Savepoints;
use Preflow\Data\Schema\RecordTables;
use Preflow\Data\Schema\RecordTableSync;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** DataManager::deleteManyIn() and Savepoints on one storage (the using class gives it). */
trait BulkDeleteSavepointScenarios
{
    abstract protected function dataManager(string $storage): DataManager;

    /** @return list<string> */
    abstract protected function storagesWithTransactions(): array;

    private function type(DataManager $dm, string $storage): TypeDefinition
    {
        $def = new TypeDefinition('thing', 'thing', $storage, ['name' => new TypeFieldDefinition('name', 'string')], idField: 'id');
        (new RecordTableSync($dm))->syncAll([RecordTables::entry($def)]);

        return $def;
    }

    private function save(DataManager $dm, TypeDefinition $def, string $id): void
    {
        $dm->saveType(new DynamicRecord($def, ['id' => $id, 'name' => $id]), validate: false);
    }

    /** @return list<string> */
    private function ids(DataManager $dm, TypeDefinition $def): array
    {
        $ids = array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), array_values($dm->queryIn($def)->get()->items()));
        sort($ids);

        return $ids;
    }

    #[DataProvider('storages')]
    public function test_delete_many_in_chunks(string $storage): void
    {
        $dm = $this->dataManager($storage);
        $def = $this->type($dm, $storage);
        $all = array_map(static fn (int $i): string => sprintf('r%04d', $i), range(1, 1203));
        foreach ($all as $id) {
            $this->save($dm, $def, $id);
        }

        $dm->deleteManyIn($def, [...array_slice($all, 0, 1200), 'r0001', 'unknown']);

        $this->assertSame(['r1201', 'r1202', 'r1203'], $this->ids($dm, $def));
        $dm->deleteManyIn($def, []);
        $this->assertCount(3, $this->ids($dm, $def));
    }

    #[DataProvider('storages')]
    public function test_a_failing_savepoint_leaves_the_transaction_usable(string $storage): void
    {
        $dm = $this->dataManager($storage);
        $def = $this->type($dm, $storage);
        $driver = $dm->driver($storage);
        if (!in_array($storage, $this->storagesWithTransactions(), true)) {
            $this->assertNotInstanceOf(Savepoints::class, $driver);

            return;
        }
        $this->assertInstanceOf(Savepoints::class, $driver);
        $pdo = $driver->pdo();

        $pdo->beginTransaction();
        $this->save($dm, $def, 'outer');
        try {
            $driver->savepoint(function () use ($dm, $def): void {
                $this->save($dm, $def, 'inner');
                $dm->execute('INSERT INTO no_such_table VALUES (1)', [], $def->storage);
            });
            $this->fail('the failure is rethrown');
        } catch (\Throwable) {
        }
        $driver->savepoint(fn () => $this->save($dm, $def, 'kept'));
        $pdo->commit();
        $this->assertSame(['kept', 'outer'], $this->ids($dm, $def));

        // Rolled back with the caller's transaction.
        $pdo->beginTransaction();
        $driver->savepoint(fn () => $this->save($dm, $def, 'gone'));
        $pdo->rollBack();
        $this->assertSame(['kept', 'outer'], $this->ids($dm, $def));

        // Without a transaction it simply runs.
        $this->assertSame('ran', $driver->savepoint(static fn (): string => 'ran'));
    }
}
