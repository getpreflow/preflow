<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use Preflow\Data\DataManager;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;

final class ImportReplacesJsonTest extends ImportReplacesTestCase
{
    protected function storage(): string
    {
        return 'json';
    }

    /** JSON files refuse no row: a driver that fails on the record "z". */
    protected function failingData(DataManager $dm): DataManager
    {
        $failing = new class ($dm->driver('json')) implements StorageDriver {
            public function __construct(private readonly StorageDriver $inner) {}

            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
            {
                return $this->inner->findOne($type, $id, $idField);
            }

            public function findMany(string $type, Query $query): ResultSet
            {
                return $this->inner->findMany($type, $query);
            }

            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
            {
                if ($id === 'z') {
                    throw new \RuntimeException('forced failure on z');
                }
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

            public function rawQuery(string $sql, array $bindings = []): array
            {
                return $this->inner->rawQuery($sql, $bindings);
            }
        };

        return new DataManager(['json' => $failing, 'default' => $failing], 'default', $dm->getTypeRegistry());
    }
}
