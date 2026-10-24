<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\RelationLabels;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;

final class RelationLabelsTest extends TestCase
{
    private string $base;
    private DataManager $dm;
    private TypeRegistry $registry;
    /** @var array<string, int> table => findMany calls */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/folio_rellabels_' . bin2hex(random_bytes(4));
        mkdir($this->base . '/m', 0777, true);
        mkdir($this->base . '/s', 0777, true);
        $models = [
            'tag' => ['key' => 'tag', 'table' => 'tag', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['code' => ['type' => 'number'], 'name' => ['type' => 'string'], 'short' => ['type' => 'string']]],
            'person' => ['key' => 'person', 'table' => 'person', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['name' => ['type' => 'string']]],
            'item' => ['key' => 'item', 'table' => 'item', 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => [
                    'title' => ['type' => 'string'],
                    'tags' => ['type' => 'relation', 'relation' => ['to' => 'tag', 'multiple' => true]],
                    'main' => ['type' => 'relation', 'relation' => ['to' => 'tag', 'labelField' => 'short']],
                    'owner' => ['type' => 'relation', 'relation' => ['to' => 'person']],
                ]],
        ];
        foreach ($models as $k => $m) {
            file_put_contents($this->base . "/m/$k.json", json_encode($m));
        }
        $this->registry = new TypeRegistry($this->base . '/m');
        $this->calls = new \ArrayObject();
        $this->dm = new DataManager(['json' => $this->countingDriver(new JsonFileDriver($this->base . '/s'))], 'json', $this->registry, new ValidatorFactory(new RuleFactory()));

        $this->save('tag', ['uuid' => 't1', 'name' => 'red', 'short' => 'R']);
        $this->save('tag', ['uuid' => 't2', 'name' => 'blue', 'short' => '']);
        $this->save('tag', ['uuid' => 't3', 'name' => 'green']);
        $this->save('person', ['uuid' => 'p1', 'name' => 'Ada']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    public function test_labels_a_page_with_one_query_per_target(): void
    {
        $item = $this->registry->get('item');
        $records = [
            DynamicRecord::fromArray($item, ['uuid' => 'i1', 'tags' => json_encode(['t1', 't2']), 'main' => 't1', 'owner' => 'p1']),
            DynamicRecord::fromArray($item, ['uuid' => 'i2', 'tags' => json_encode(['t2', 'gone']), 'main' => 't2', 'owner' => '']),
        ];
        $this->calls->exchangeArray([]);

        $labels = (new RelationLabels($this->dm, new RecordLabeler()))->forRecords($item, ['tags', 'main', 'owner'], $records);

        $this->assertSame(['t1' => 'red', 't2' => 'blue'], $labels['tags']);
        $this->assertSame(['t1' => 'R', 't2' => 'blue'], $labels['main']); // labelField, else the record's label
        $this->assertSame(['p1' => 'Ada'], $labels['owner']);
        $this->assertSame(['tag' => 1, 'person' => 1], $this->calls->getArrayCopy());
    }

    public function test_no_ids_no_query(): void
    {
        $item = $this->registry->get('item');
        $this->calls->exchangeArray([]);

        $labels = (new RelationLabels($this->dm, new RecordLabeler()))->forRecords($item, ['owner'], [DynamicRecord::fromArray($item, ['uuid' => 'i1'])]);

        $this->assertSame(['owner' => []], $labels);
        $this->assertSame([], $this->calls->getArrayCopy());
    }

    public function test_ids_of_stored_values(): void
    {
        $this->assertSame(['a', 'b'], RelationLabels::ids('["a","b"]'));
        $this->assertSame(['a'], RelationLabels::ids('a'));
        $this->assertSame(['a'], RelationLabels::ids(['a', '', null]));
        $this->assertSame([], RelationLabels::ids(''));
        $this->assertSame([], RelationLabels::ids(null));
    }

    public function test_options_up_to_the_limit(): void
    {
        $labels = new RelationLabels($this->dm, new RecordLabeler());

        $this->assertSame(['t1' => 'red', 't2' => 'blue', 't3' => 'green'], $labels->options('tag', null, 3));
        $this->assertSame(['t1' => 'R', 't2' => 'blue', 't3' => 'green'], $labels->options('tag', 'short', 3));
        $this->assertNull($labels->options('tag', null, 2)); // more records than the limit: no list
        $this->assertNull($labels->options('nope', null));
    }

    public function test_labels_of_ids(): void
    {
        $this->assertSame(['t3' => 'green', 't1' => 'red'], (new RelationLabels($this->dm, new RecordLabeler()))->labels('tag', ['t3', 't1', 'gone'], null));
    }

    /** @param array<string, mixed> $data */
    private function save(string $type, array $data): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get($type), $data), validate: false);
    }

    private function countingDriver(StorageDriver $inner): StorageDriver
    {
        $calls = $this->calls;

        return new class ($inner, $calls) implements StorageDriver {
            public function __construct(private StorageDriver $inner, private \ArrayObject $calls) {}
            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return $this->inner->findOne($type, $id, $idField); }
            public function findMany(string $type, Query $query): ResultSet
            {
                $this->calls[$type] = ($this->calls[$type] ?? 0) + 1;

                return $this->inner->findMany($type, $query);
            }
            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }
            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }
            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }
            public function lastInsertId(): string|int { return $this->inner->lastInsertId(); }
            public function rawQuery(string $sql, array $bindings = []): array { return $this->inner->rawQuery($sql, $bindings); }
        };
    }
}
