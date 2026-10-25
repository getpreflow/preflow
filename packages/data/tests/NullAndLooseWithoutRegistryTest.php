<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/** whereAny "=" with null, and loose search on a code-defined type that no registry knows. */
final class NullAndLooseWithoutRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nl_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            is_dir($f) ? array_map('unlink', glob($f . '/*') ?: []) + [rmdir($f)] : unlink($f);
        }
        @rmdir($this->dir);
    }

    private function def(string $storage): TypeDefinition
    {
        return new TypeDefinition(
            key: 'thing',
            table: 'things',
            storage: $storage,
            fields: [
                'title' => new TypeFieldDefinition('title', 'string'),
                'flag' => new TypeFieldDefinition('flag', 'boolean'),
                'tags' => new TypeFieldDefinition('tags', 'relation', config: ['relation' => ['to' => 'tag', 'multiple' => true]]),
            ],
            idField: 'uuid',
            locales: Locales::single('en'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    private function dm(string $storage, TypeDefinition $def): DataManager
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE things (uuid TEXT PRIMARY KEY, title TEXT, flag TEXT, tags TEXT)');

        // No TypeRegistry: the type exists only as this definition.
        return new DataManager(drivers: ['json' => new JsonFileDriver($this->dir), 'sqlite' => new SqliteDriver($pdo)], defaultDriver: $storage);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storages')]
    public function test_a_null_value_matches_a_missing_field(string $storage): void
    {
        $def = $this->def($storage);
        $dm = $this->dm($storage, $def);
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'unset']), false);
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'b', 'title' => 'off', 'flag' => false]), false);
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'c', 'title' => 'on', 'flag' => true]), false);

        $ids = static function (DataManager $dm, TypeDefinition $def, mixed $value): array {
            $found = array_map(static fn ($r) => $r->getId(), $dm->queryIn($def)->whereAny([['field' => 'flag', 'operator' => '=', 'value' => $value]])->get()->items());
            sort($found);

            return $found;
        };

        $this->assertSame(['a'], $ids($dm, $def, null));
        $this->assertSame(['c'], $ids($dm, $def, true));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('storages')]
    public function test_loose_search_reaches_relation_fields_without_a_type_registry(string $storage): void
    {
        $def = $this->def($storage);
        $dm = $this->dm($storage, $def);
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'a', 'title' => 'Red barn', 'tags' => ['t1']]), false);
        $dm->saveType(DynamicRecord::fromArray($def, ['uuid' => 'b', 'title' => 'Blue lake', 'tags' => []]), false);

        // The registry-less manager used to be fine only because it never looked at relations; it must
        // still work now that relation fields are read from the definition (an unknown target matches nothing).
        $found = array_map(static fn ($r) => $r->getId(), $dm->queryIn($def)->searchLoose('barn')->get()->items());

        $this->assertSame(['a'], $found);
    }
}
