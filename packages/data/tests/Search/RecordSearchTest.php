<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Search;

require_once __DIR__ . '/Fixtures/RankedFakeDriver.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\Tests\Search\Fixtures\RankedFakeDriver;
use Preflow\Data\Search\RecordHit;
use Preflow\Data\Search\RecordSearch;
use Preflow\Data\TypeRegistry;
use Preflow\Data\UnsupportedStorage;

final class RecordSearchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rs_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    private function search(string $storage): RecordSearch
    {
        $this->model('game', $storage, ['title' => ['type' => 'string', 'translatable' => true], 'notes' => ['type' => 'text']], 'title');
        $this->model('designer', $storage, ['pin' => ['type' => 'string', 'hidden' => true], 'name' => ['type' => 'string']]);

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE game (uuid TEXT PRIMARY KEY, title TEXT, notes TEXT, _locales TEXT)');
        $pdo->exec('CREATE TABLE designer (uuid TEXT PRIMARY KEY, pin TEXT, name TEXT)');

        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en', 'de'], 'en'));
        $dm = new DataManager(
            drivers: ['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'sqlite',
            typeRegistry: $registry,
        );
        foreach ([
            ['uuid' => 'g1', 'title' => ['en' => 'Zoo Night', 'de' => 'Zoonacht'], 'notes' => ''],
            ['uuid' => 'g2', 'title' => ['en' => 'Zoo Day', 'de' => 'Zootag'], 'notes' => ''],
            ['uuid' => 'g3', 'title' => ['en' => 'Lisboa', 'de' => 'Lissabon'], 'notes' => 'zoo mentioned'],
            ['uuid' => 'g4', 'title' => ['en' => 'Kanban', 'de' => 'Kanban'], 'notes' => ''],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('game'), $row), validate: false);
        }
        foreach ([
            ['uuid' => 'd1', 'pin' => 'p1', 'name' => 'Zoe Zookeeper'],
            ['uuid' => 'd2', 'pin' => 'p2', 'name' => 'Uwe Rosenberg'],
        ] as $row) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('designer'), $row), validate: false);
        }

        return new RecordSearch($dm);
    }

    /** @param list<RecordHit> $hits @return list<string> "type:id" */
    private static function keys(array $hits): array
    {
        return array_map(static fn (RecordHit $h): string => $h->type . ':' . $h->id, $hits);
    }

    #[DataProvider('storages')]
    public function test_searches_two_types_with_titles(string $storage): void
    {
        $hits = $this->search($storage)->search('zoo', ['game', 'designer']);

        $this->assertContainsOnlyInstancesOf(RecordHit::class, $hits);
        $byKey = [];
        foreach ($hits as $hit) {
            $byKey[$hit->type . ':' . $hit->id] = $hit->title;
        }
        ksort($byKey);
        $this->assertSame([
            'designer:d1' => 'Zoe Zookeeper',
            'game:g1' => 'Zoo Night',
            'game:g2' => 'Zoo Day',
            'game:g3' => 'Lisboa',
        ], $byKey);
    }

    #[DataProvider('storages')]
    public function test_per_type_caps_each_type(string $storage): void
    {
        $hits = $this->search($storage)->search('zoo', ['game', 'designer'], perType: 2);

        $types = array_map(static fn (RecordHit $h): string => $h->type, $hits);
        $this->assertSame(['game', 'game', 'designer'], $types);
    }

    #[DataProvider('storages')]
    public function test_hits_follow_the_given_type_order(string $storage): void
    {
        $hits = $this->search($storage)->search('zoo', ['designer', 'game']);

        $types = array_map(static fn (RecordHit $h): string => $h->type, $hits);
        $this->assertSame(['designer', 'game', 'game', 'game'], $types);
    }

    #[DataProvider('storages')]
    public function test_a_term_under_two_characters_finds_nothing(string $storage): void
    {
        $search = $this->search($storage);

        $this->assertSame([], $search->search('z', ['game', 'designer']));
        $this->assertSame([], $search->search('  z  ', ['game', 'designer']));
        $this->assertSame([], $search->search('   ', ['game', 'designer']));
        $this->assertNotSame([], $search->search(' zo ', ['game']));
    }

    #[DataProvider('storages')]
    public function test_an_unknown_type_is_skipped(string $storage): void
    {
        $hits = $this->search($storage)->search('rosenberg', ['nope', 'designer']);

        $this->assertSame(['designer:d2'], self::keys($hits));
        $this->assertSame('Uwe Rosenberg', $hits[0]->title);
    }

    public function test_a_data_manager_without_types_finds_nothing(): void
    {
        $search = new RecordSearch(new DataManager(['json' => new JsonFileDriver($this->dir . '/store')], 'json'));

        $this->assertSame([], $search->search('zoo', ['game']));
    }

    /**
     * A flagged type "post" (title, body) on $driver under the storage name "main", with $rows
     * saved in the given order: id => [title, body].
     *
     * @param array<string, array{string, string}> $rows
     */
    private function posts(StorageDriver $driver, array $rows): DataManager
    {
        file_put_contents($this->dir . '/models/post.json', json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'main', 'id_field' => 'uuid', 'titleField' => 'title', 'fullText' => true,
            'fields' => ['title' => ['type' => 'string'], 'body' => ['type' => 'text']],
        ]));
        $registry = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));
        $dm = new DataManager(drivers: ['main' => $driver], defaultDriver: 'main', typeRegistry: $registry);
        foreach ($rows as $id => [$title, $body]) {
            $dm->saveType(DynamicRecord::fromArray($registry->get('post'), ['uuid' => $id, 'title' => $title, 'body' => $body]), validate: false);
        }

        return $dm;
    }

    /** @return list<string> the ids loose search finds for $term, in storage order */
    private static function looseIds(DataManager $dm, string $term): array
    {
        return array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $dm->queryType('post')->searchLoose($term)->get()->items());
    }

    private function rankedDriver(): RankedFakeDriver
    {
        $driver = new RankedFakeDriver(new JsonFileDriver($this->dir . '/ranked'));
        $driver->declare('post', ['title', 'body']);

        return $driver;
    }

    public function test_ranked_storage_orders_hits_by_score_and_keeps_the_set(): void
    {
        $dm = $this->posts($driver = $this->rankedDriver(), [
            'p1' => ['Garden', 'a shed'],                              // 1
            'p2' => ['Garden garden', 'garden gnomes'],                // 3
            'p3' => ['Kitchen', 'no match here'],                      // 0, not found
            'p4' => ['Garden party', 'in the garden'],                 // 2
            'p5' => ['Gardens of stone', 'garden garden garden'],      // 4
            'p6' => ['City garden', 'small'],                          // 1
            'p7' => ['Rose garden', 'garden roses, garden paths, garden'], // 4
            'p8' => ['Garden tools', 'rakes'],                         // 1
        ]);

        $hits = (new RecordSearch($dm))->search('garden', ['post'], perType: 5);

        $this->assertSame(['p5', 'p7', 'p2', 'p4', 'p1'], array_map(static fn (RecordHit $h): string => $h->id, $hits), 'best first; ties in storage order');
        $this->assertSame([4.0, 4.0, 3.0, 2.0, 1.0], array_map(static fn (RecordHit $h): ?float => $h->score, $hits));
        $loose = self::looseIds($dm, 'garden');
        $this->assertCount(7, $loose);
        $this->assertSame([], array_diff(array_map(static fn (RecordHit $h): string => $h->id, $hits), $loose), 'no record outside what loose search finds');
        $this->assertCount(1, $driver->scored);
        $this->assertSame($loose, array_map('strval', $driver->scored[0]['ids']), 'the loose-search hits are what gets scored');
    }

    public function test_ties_keep_storage_order(): void
    {
        $dm = $this->posts($this->rankedDriver(), [
            'c' => ['Zoo one', ''],
            'a' => ['Zoo two', ''],
            'd' => ['Zoo zoo', ''],
            'b' => ['Zoo three', ''],
        ]);
        $storage = self::looseIds($dm, 'zoo');
        $ties = array_values(array_diff($storage, ['d']));

        $hits = (new RecordSearch($dm))->search('zoo', ['post']);

        $this->assertSame(['d', ...$ties], array_map(static fn (RecordHit $h): string => $h->id, $hits));
        $this->assertSame([2.0, 1.0, 1.0, 1.0], array_map(static fn (RecordHit $h): ?float => $h->score, $hits));
    }

    public function test_storage_without_ranking_keeps_storage_order_and_reads_only_per_type(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE post (uuid TEXT PRIMARY KEY, title TEXT, body TEXT)');
        $spy = new class (new SqliteDriver($pdo)) implements StorageDriver {
            /** @var list<?int> the limit of every findMany() on post */
            public array $limits = [];

            public function __construct(private readonly SqliteDriver $inner) {}

            public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array { return $this->inner->findOne($type, $id, $idField); }

            public function findMany(string $type, Query $query): ResultSet
            {
                if ($type === 'post') {
                    $this->limits[] = $query->getLimit();
                }

                return $this->inner->findMany($type, $query);
            }

            public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void { $this->inner->save($type, $id, $data, $idField); }

            public function delete(string $type, string|int $id, string $idField = 'uuid'): void { $this->inner->delete($type, $id, $idField); }

            public function exists(string $type, string|int $id, string $idField = 'uuid'): bool { return $this->inner->exists($type, $id, $idField); }

            public function lastInsertId(): string|int { return $this->inner->lastInsertId(); }
        };
        $rows = [];
        foreach (['s1' => 1, 's2' => 3, 's3' => 2, 's4' => 5, 's5' => 1, 's6' => 4, 's7' => 2] as $id => $times) {
            $rows[$id] = ['Garden', str_repeat('garden ', $times)];
        }
        $dm = $this->posts($spy, $rows);
        $this->assertFalse($dm->canScore($dm->getTypeRegistry()->get('post')), 'flagged, but the storage cannot score');
        $storage = self::looseIds($dm, 'garden');
        $spy->limits = [];

        $hits = (new RecordSearch($dm))->search('garden', ['post'], perType: 5);

        $this->assertSame(array_slice($storage, 0, 5), array_map(static fn (RecordHit $h): string => $h->id, $hits));
        $this->assertSame([null, null, null, null, null], array_map(static fn (RecordHit $h): ?float => $h->score, $hits));
        $this->assertSame([5], $spy->limits, 'today\'s query: limit $perType, not the candidate cap');
    }

    public function test_a_type_whose_fields_the_ranked_storage_cannot_score_keeps_storage_order(): void
    {
        $driver = new RankedFakeDriver(new JsonFileDriver($this->dir . '/ranked'));
        $driver->declare('post', ['title']); // not the type's text fields
        $dm = $this->posts($driver, ['x' => ['Zoo', ''], 'y' => ['Zoo zoo', 'zoo']]);

        $hits = (new RecordSearch($dm))->search('zoo', ['post']);

        $this->assertSame(self::looseIds($dm, 'zoo'), array_map(static fn (RecordHit $h): string => $h->id, $hits));
        $this->assertSame([null, null], array_map(static fn (RecordHit $h): ?float => $h->score, $hits));
        $this->assertSame([], $driver->scored);
    }

    public function test_score_in_refuses_a_storage_that_cannot_score_the_type(): void
    {
        $plain = $this->posts(new JsonFileDriver($this->dir . '/plain'), ['x' => ['Zoo', '']]);
        try {
            $plain->scoreIn($plain->getTypeRegistry()->get('post'), 'zoo', ['x']);
            $this->fail('no RankedSearch: UnsupportedStorage');
        } catch (UnsupportedStorage $e) {
            $this->assertSame(\Preflow\Data\RankedSearch::class, $e->capability);
        }

        $driver = new RankedFakeDriver(new JsonFileDriver($this->dir . '/ranked'));
        $dm = $this->posts($driver, ['x' => ['Zoo', '']]);
        $this->expectException(\LogicException::class);
        $dm->scoreIn($dm->getTypeRegistry()->get('post'), 'zoo', ['x']); // nothing declared: canScore() is false
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function model(string $key, string $storage, array $fields, ?string $titleField = null): void
    {
        $schema = ['key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid', 'fields' => $fields];
        if ($titleField !== null) {
            $schema['titleField'] = $titleField;
        }
        file_put_contents($this->dir . '/models/' . $key . '.json', json_encode($schema));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
