<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\Singletons;
use Preflow\Folio\Content\TypeCatalog;

final class SingletonsTest extends TestCase
{
    public function test_memoized_per_request(): void
    {
        $dir = sys_get_temp_dir() . '/sgl_' . bin2hex(random_bytes(4));
        mkdir($dir . '/models', 0777, true);
        file_put_contents($dir . '/models/site.json', json_encode([
            'key' => 'site', 'table' => 'site', 'storage' => 'json', 'id_field' => 'uuid', 'singleton' => true,
            'fields' => ['name' => ['type' => 'string']],
        ]));
        $inner = new JsonFileDriver($dir . '/store');
        $spy = new class ($inner) implements StorageDriver {
            public int $finds = 0;

            public function __construct(private JsonFileDriver $inner) {}

            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
            {
                $this->finds++;
                return $this->inner->findOne($type, $id, $idField);
            }

            public function findMany(string $type, Query $query): ResultSet { return $this->inner->findMany($type, $query); }
            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }
            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }
            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }
            public function lastInsertId(): string|int { return $this->inner->lastInsertId(); }
            public function rawQuery(string $sql, array $bindings = []): array { return []; }
        };
        $registry = new TypeRegistry($dir . '/models');
        $dm = new DataManager(drivers: ['json' => $spy], defaultDriver: 'json', typeRegistry: $registry);
        $dm->saveType(DynamicRecord::fromArray($registry->get('site'), ['uuid' => 'site', 'name' => 'S']), validate: false);

        $singletons = new Singletons($dm, new TypeCatalog($dir . '/models'), new ContentLocale(Locales::single('en')));

        $this->assertSame('S', $singletons->get('site')['name']);
        $this->assertSame('S', $singletons->get('site')['name']);
        $this->assertSame(1, $spy->finds);
        $this->assertNull($singletons->get('nope'));
    }
}
