<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DeletePlan;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

final class DeletePlanTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;
    private TypeRegistry $registry;

    /** @var array<string, array<string, array<string, mixed>>> type key => field name => config */
    private const TYPES = [
        'author' => [
            'name' => ['type' => 'string'],
            'mentor' => ['type' => 'relation', 'relation' => ['to' => 'author', 'onDelete' => 'restrict']],
        ],
        'book' => [
            'title' => ['type' => 'string'],
            'authors' => ['type' => 'relation', 'relation' => ['to' => 'author', 'multiple' => true]],
            'editor' => ['type' => 'relation', 'relation' => ['to' => 'author']],
        ],
        'chapter' => [
            'title' => ['type' => 'string'],
            'book' => ['type' => 'relation', 'relation' => ['to' => 'book', 'onDelete' => 'cascade']],
        ],
        'note' => [
            'text' => ['type' => 'text'],
            'chapter' => ['type' => 'relation', 'relation' => ['to' => 'chapter', 'onDelete' => 'cascade']],
        ],
        'contract' => [
            'author' => ['type' => 'relation', 'relation' => ['to' => 'author', 'onDelete' => 'restrict']],
        ],
        'citation' => [
            'chapter' => ['type' => 'relation', 'relation' => ['to' => 'chapter', 'onDelete' => 'restrict']],
        ],
        'review' => [
            'book' => ['type' => 'relation', 'relation' => ['to' => 'book', 'onDelete' => 'cascade']],
            'chapters' => ['type' => 'relation', 'relation' => ['to' => 'chapter', 'multiple' => true]],
        ],
        'ping' => [
            'pong' => ['type' => 'relation', 'relation' => ['to' => 'pong', 'onDelete' => 'cascade']],
        ],
        'pong' => [
            'ping' => ['type' => 'relation', 'relation' => ['to' => 'ping', 'onDelete' => 'cascade']],
        ],
    ];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dp_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    /** @return iterable<string, array{string, string}> */
    public static function storages(): iterable
    {
        yield 'all sqlite' => ['sqlite', 'sqlite'];
        yield 'all json' => ['json', 'json'];
        yield 'sqlite pointers, json targets' => ['sqlite', 'json'];
        yield 'json pointers, sqlite targets' => ['json', 'sqlite'];
    }

    /**
     * @param string $pointerStorage storage of book, note and contract
     * @param string $targetStorage storage of author and chapter
     */
    private function dm(string $pointerStorage = 'sqlite', string $targetStorage = 'sqlite'): DataManager
    {
        $storage = [
            'author' => $targetStorage, 'chapter' => $targetStorage,
            'book' => $pointerStorage, 'note' => $pointerStorage, 'contract' => $pointerStorage,
            'ping' => $pointerStorage, 'pong' => $targetStorage,
            'citation' => $pointerStorage, 'review' => $pointerStorage,
        ];

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (self::TYPES as $key => $fields) {
            file_put_contents($this->dir . "/models/{$key}.json", json_encode([
                'key' => $key, 'table' => $key, 'storage' => $storage[$key], 'id_field' => 'uuid', 'fields' => $fields,
            ]));
            $this->createTable($key, array_keys($fields));
        }
        $this->createTable('contract_pub', ['author']);

        $this->registry = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));

        return new DataManager(
            drivers: ['sqlite' => new SqliteDriver($this->pdo), 'json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'sqlite',
            typeRegistry: $this->registry,
        );
    }

    /** @param list<string> $columns */
    private function createTable(string $table, array $columns): void
    {
        $cols = implode('', array_map(static fn (string $c) => ", {$c} TEXT", $columns));
        $this->pdo->exec("CREATE TABLE {$table} (uuid TEXT PRIMARY KEY{$cols})");
    }

    /** @param list<array<string, mixed>> $rows */
    private function seed(DataManager $dm, string $type, array $rows): void
    {
        foreach ($rows as $row) {
            $dm->saveType(DynamicRecord::fromArray($this->registry->get($type), $row), validate: false);
        }
    }

    public function test_value_without_matches_what_the_executor_writes(): void
    {
        $this->assertSame('["b","c"]', DeletePlan::valueWithout('["a","b","c"]', ['a']));
        $this->assertSame('[]', DeletePlan::valueWithout(['a'], ['a']));
        $this->assertSame('', DeletePlan::valueWithout('a', ['a']));
        $this->assertSame('b', DeletePlan::valueWithout('b', ['a']));
        $this->assertSame('[ "b" ]', DeletePlan::valueWithout('[ "b" ]', ['a'])); // untouched text
    }

    private function shadow(string $key): TypeDefinition
    {
        $def = $this->registry->get($key);

        return new TypeDefinition($def->key, $key . '_pub', 'sqlite', $def->fields, $def->idField);
    }

    /** @return list<string> sorted ids of a type */
    private function ids(DataManager $dm, string $type): array
    {
        $ids = array_map(static fn (DynamicRecord $r) => (string) $r->getId(), $dm->queryType($type)->get()->items());
        sort($ids);

        return $ids;
    }

    #[DataProvider('storages')]
    public function test_detach_removes_the_id_from_a_multiple_and_empties_a_single(string $p, string $t): void
    {
        $dm = $this->dm($p, $t);
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A'], ['uuid' => 'a2', 'name' => 'B'], ['uuid' => 'a3', 'name' => 'C']]);
        $this->seed($dm, 'book', [
            ['uuid' => 'b1', 'title' => 'One', 'authors' => '["a3","a1","a2"]', 'editor' => 'a1'],
            ['uuid' => 'b2', 'title' => 'Two', 'authors' => '["a2"]', 'editor' => 'a2'],
            ['uuid' => 'b3', 'title' => 'Three', 'authors' => '["a1"]', 'editor' => ''],
        ]);

        $plan = $dm->deletePlan('author', ['a1']);

        $this->assertSame(['author' => ['a1']], $plan->deletes());
        $detaches = $plan->detaches();
        usort($detaches, static fn ($x, $y) => [$x['id'], $x['field']] <=> [$y['id'], $y['field']]);
        $this->assertSame([
            ['type' => 'book', 'id' => 'b1', 'field' => 'authors', 'remove' => ['a1']],
            ['type' => 'book', 'id' => 'b1', 'field' => 'editor', 'remove' => ['a1']],
            ['type' => 'book', 'id' => 'b3', 'field' => 'authors', 'remove' => ['a1']],
        ], $detaches);
        $this->assertSame([], $plan->restrictions());
        $this->assertFalse($plan->restricted());
        $this->assertSame(['deleted' => [], 'detached' => ['book' => 2], 'restricted' => []], $plan->counts());

        $dm->executeDeletePlan($plan);

        $this->assertSame(['a2', 'a3'], $this->ids($dm, 'author'));
        $b1 = $dm->findType('book', 'b1');
        $this->assertSame('["a3","a2"]', $b1->get('authors'));
        $this->assertSame('', $b1->get('editor'));
        $this->assertSame('One', $b1->get('title'));
        $this->assertSame('[]', $dm->findType('book', 'b3')->get('authors'));
        $this->assertSame('["a2"]', $dm->findType('book', 'b2')->get('authors'));
        $this->assertSame('a2', $dm->findType('book', 'b2')->get('editor'));
    }

    #[DataProvider('storages')]
    public function test_cascade_two_levels_deep(string $p, string $t): void
    {
        $dm = $this->dm($p, $t);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'title' => 'One'], ['uuid' => 'b2', 'title' => 'Two']]);
        $this->seed($dm, 'chapter', [
            ['uuid' => 'c1', 'title' => '1', 'book' => 'b1'],
            ['uuid' => 'c2', 'title' => '2', 'book' => 'b1'],
            ['uuid' => 'c3', 'title' => '3', 'book' => 'b2'],
        ]);
        $this->seed($dm, 'note', [
            ['uuid' => 'n1', 'text' => 'x', 'chapter' => 'c1'],
            ['uuid' => 'n2', 'text' => 'y', 'chapter' => 'c3'],
            ['uuid' => 'n3', 'text' => 'z', 'chapter' => 'c2'],
        ]);

        $plan = $dm->deletePlan('book', ['b1']);

        $deletes = $plan->deletes();
        foreach ($deletes as &$ids) {
            sort($ids);
        }
        unset($ids);
        $this->assertSame(['book' => ['b1'], 'chapter' => ['c1', 'c2'], 'note' => ['n1', 'n3']], $deletes);
        $this->assertSame([], $plan->detaches());
        $this->assertSame(['deleted' => ['chapter' => 2, 'note' => 2], 'detached' => [], 'restricted' => []], $plan->counts());

        $dm->executeDeletePlan($plan);

        $this->assertSame(['b2'], $this->ids($dm, 'book'));
        $this->assertSame(['c3'], $this->ids($dm, 'chapter'));
        $this->assertSame(['n2'], $this->ids($dm, 'note'));
    }

    #[DataProvider('storages')]
    public function test_cascade_cycle_terminates(string $p, string $t): void
    {
        $dm = $this->dm($p, $t);
        $this->seed($dm, 'ping', [['uuid' => 'p1', 'pong' => 'q1'], ['uuid' => 'p2', 'pong' => 'q2'], ['uuid' => 'p3', 'pong' => 'q9']]);
        $this->seed($dm, 'pong', [['uuid' => 'q1', 'ping' => 'p1'], ['uuid' => 'q2', 'ping' => 'p1'], ['uuid' => 'q9', 'ping' => 'p9']]);

        $plan = $dm->deletePlan('ping', ['p1']);

        $deletes = $plan->deletes();
        foreach ($deletes as &$ids) {
            sort($ids);
        }
        unset($ids);
        // p1 -> pongs pointing at p1 (q1, q2) -> pings pointing at q1/q2 (p1 again, p2) -> nothing new
        $this->assertSame(['ping' => ['p1', 'p2'], 'pong' => ['q1', 'q2']], $deletes);
        $this->assertSame(['deleted' => ['ping' => 1, 'pong' => 2], 'detached' => [], 'restricted' => []], $plan->counts());

        $dm->executeDeletePlan($plan);
        $this->assertSame(['p3'], $this->ids($dm, 'ping'));
        $this->assertSame(['q9'], $this->ids($dm, 'pong'));
    }

    #[DataProvider('storages')]
    public function test_restrict_is_counted_and_execution_refused_with_nothing_changed(string $p, string $t): void
    {
        $dm = $this->dm($p, $t);
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A'], ['uuid' => 'a2', 'name' => 'B']]);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'title' => 'One', 'authors' => '["a1"]', 'editor' => 'a1']]);
        $this->seed($dm, 'contract', [['uuid' => 'k1', 'author' => 'a1'], ['uuid' => 'k2', 'author' => 'a2']]);
        $this->pdo->exec("INSERT INTO contract_pub (uuid, author) VALUES ('k1', 'a1'), ('k3', 'a1'), ('k4', 'a2')");

        $plan = $dm->deletePlan('author', ['a1']);
        $this->assertSame(['contract' => 1], $plan->restrictions());
        $this->assertTrue($plan->restricted());

        $copies = fn (TypeDefinition $def) => $def->key === 'contract' ? [$this->shadow('contract')] : [];
        $plan = $dm->deletePlan('author', ['a1'], $copies);
        $this->assertSame(['contract' => 3], $plan->restrictions());
        $this->assertSame(['contract' => 3], $plan->counts()['restricted']);
        $this->assertCount(2, $plan->detaches(), 'detaches come from the working type only');

        try {
            $dm->executeDeletePlan($plan);
            $this->fail('A restricted plan must be refused');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['a1', 'a2'], $this->ids($dm, 'author'));
        $this->assertSame('["a1"]', $dm->findType('book', 'b1')->get('authors'));
        $this->assertSame('a1', $dm->findType('book', 'b1')->get('editor'));
    }

    #[DataProvider('storages')]
    public function test_a_restrict_reached_only_through_a_cascade_blocks_the_whole_plan(string $p, string $t): void
    {
        $dm = $this->dm($p, $t);
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A']]);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'title' => 'One', 'authors' => '["a1"]']]);
        $this->seed($dm, 'chapter', [['uuid' => 'c1', 'title' => '1', 'book' => 'b1'], ['uuid' => 'c2', 'title' => '2', 'book' => 'b1']]);
        $this->seed($dm, 'note', [['uuid' => 'n1', 'text' => 'x', 'chapter' => 'c2']]);
        $this->seed($dm, 'citation', [['uuid' => 'x1', 'chapter' => 'c1']]);

        // Nothing points at the book with "restrict"; a chapter it cascades to is cited.
        $plan = $dm->deletePlan('book', ['b1']);
        $this->assertSame(['citation' => 1], $plan->restrictions());
        $this->assertTrue($plan->restricted());

        try {
            $dm->executeDeletePlan($plan);
            $this->fail('A restricted plan must be refused');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['b1'], $this->ids($dm, 'book'));
        $this->assertSame(['c1', 'c2'], $this->ids($dm, 'chapter'));
        $this->assertSame(['n1'], $this->ids($dm, 'note'));
        $this->assertSame(['x1'], $this->ids($dm, 'citation'));
    }

    #[DataProvider('storages')]
    public function test_a_record_reached_by_cascade_and_detach_is_deleted_not_detached(string $p, string $t): void
    {
        $dm = $this->dm($p, $t);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'title' => 'One'], ['uuid' => 'b2', 'title' => 'Two']]);
        $this->seed($dm, 'chapter', [['uuid' => 'c1', 'title' => '1', 'book' => 'b1'], ['uuid' => 'c3', 'title' => '3', 'book' => 'b2']]);
        $this->seed($dm, 'review', [
            // cascades with b1, and would lose c1 by "detach"
            ['uuid' => 'r1', 'book' => 'b1', 'chapters' => '["c1","c3"]'],
            // only detached
            ['uuid' => 'r2', 'book' => 'b2', 'chapters' => '["c1","c3"]'],
        ]);

        $plan = $dm->deletePlan('book', ['b1']);

        $deletes = $plan->deletes();
        sort($deletes['review']);
        $this->assertSame(['r1'], $deletes['review']);
        $this->assertSame([['type' => 'review', 'id' => 'r2', 'field' => 'chapters', 'remove' => ['c1']]], $plan->detaches());
        $this->assertSame(['review' => 1], $plan->counts()['detached']);

        $dm->executeDeletePlan($plan);

        $this->assertSame(['r2'], $this->ids($dm, 'review'), 'r1 is gone, not saved back by a detach');
        $this->assertNull($dm->findType('review', 'r1'));
        $this->assertSame('["c3"]', $dm->findType('review', 'r2')->get('chapters'));
    }

    public function test_only_a_copy_restricts(): void
    {
        $dm = $this->dm();
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A']]);
        $this->pdo->exec("INSERT INTO contract_pub (uuid, author) VALUES ('k1', 'a1')");

        $plan = $dm->deletePlan('author', ['a1'], fn (TypeDefinition $def) => $def->key === 'contract' ? [$this->shadow('contract')] : []);

        $this->assertSame(['contract' => 1], $plan->restrictions());
        $this->assertTrue($plan->restricted());
    }

    public function test_records_deleted_in_the_same_plan_do_not_restrict(): void
    {
        $dm = $this->dm();
        $this->seed($dm, 'author', [
            ['uuid' => 'a1', 'name' => 'A'],
            ['uuid' => 'a2', 'name' => 'B', 'mentor' => 'a1'],
            ['uuid' => 'a3', 'name' => 'C', 'mentor' => 'a1'],
        ]);

        $this->assertSame(['author' => 2], $dm->deletePlan('author', ['a1'])->restrictions());
        $this->assertSame(['author' => 1], $dm->deletePlan('author', ['a1', 'a2'])->restrictions());

        $plan = $dm->deletePlan('author', ['a1', 'a2', 'a3']);
        $this->assertFalse($plan->restricted());
        $dm->executeDeletePlan($plan);
        $this->assertSame([], $this->ids($dm, 'author'));
    }

    public function test_several_roots_are_merged_without_duplicates(): void
    {
        $dm = $this->dm();
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A'], ['uuid' => 'a2', 'name' => 'B'], ['uuid' => 'a3', 'name' => 'C']]);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'authors' => '["a1","a2","a3"]', 'editor' => 'a2'], ['uuid' => 'b2', 'title' => 'Two']]);
        $this->seed($dm, 'chapter', [['uuid' => 'c1', 'book' => 'b1'], ['uuid' => 'c2', 'book' => 'b2']]);

        $plan = $dm->deletePlan('author', ['a1', 'a2', 'a1']);
        $this->assertSame(['author' => ['a1', 'a2']], $plan->deletes());
        $detaches = $plan->detaches();
        usort($detaches, static fn ($x, $y) => $x['field'] <=> $y['field']);
        $this->assertSame([
            ['type' => 'book', 'id' => 'b1', 'field' => 'authors', 'remove' => ['a1', 'a2']],
            ['type' => 'book', 'id' => 'b1', 'field' => 'editor', 'remove' => ['a2']],
        ], $detaches);
        $this->assertSame(['book' => 1], $plan->counts()['detached']);

        $plan = $dm->deletePlan('book', ['b1', 'b2', 'b2']);
        $deletes = $plan->deletes();
        sort($deletes['chapter']);
        $this->assertSame(['book' => ['b1', 'b2'], 'chapter' => ['c1', 'c2']], $deletes);
        $this->assertSame(['chapter' => 2], $plan->counts()['deleted']);
    }

    public function test_a_failing_execution_rolls_back_sql_changes(): void
    {
        $dm = $this->dm();
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A']]);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'title' => 'One', 'authors' => '["a1"]'], ['uuid' => 'b2', 'title' => 'Two', 'editor' => 'a1']]);
        $this->seed($dm, 'chapter', [['uuid' => 'c1', 'book' => 'b1']]);
        $this->seed($dm, 'note', [['uuid' => 'n1', 'chapter' => 'c1']]);

        $plan = $dm->deletePlan('book', ['b1']);
        $this->assertSame(['book' => ['b1'], 'chapter' => ['c1'], 'note' => ['n1']], $plan->deletes());
        $this->pdo->exec('DROP TABLE note'); // the last delete will fail

        try {
            $dm->executeDeletePlan($plan);
            $this->fail('Execution should have failed');
        } catch (\PDOException) {
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(['b1', 'b2'], $this->ids($dm, 'book'));
        $this->assertSame(['c1'], $this->ids($dm, 'chapter'));
    }

    public function test_a_failing_detach_rolls_back_too(): void
    {
        $dm = $this->dm();
        $this->seed($dm, 'author', [['uuid' => 'a1', 'name' => 'A']]);
        $this->seed($dm, 'book', [['uuid' => 'b1', 'authors' => '["a1"]', 'editor' => 'a1']]);

        $plan = $dm->deletePlan('author', ['a1']);
        $this->pdo->exec('DROP TABLE author'); // detaches succeed, then the delete fails

        try {
            $dm->executeDeletePlan($plan);
            $this->fail('Execution should have failed');
        } catch (\PDOException) {
        }

        $this->assertSame('["a1"]', $dm->findType('book', 'b1')->get('authors'));
        $this->assertSame('a1', $dm->findType('book', 'b1')->get('editor'));
    }

    public function test_plan_is_a_value_object(): void
    {
        $plan = new DeletePlan(
            deletes: ['author' => ['a1', 'a7'], 'book' => ['b1', 'b2']],
            detaches: [['type' => 'chapter', 'id' => 'c1', 'field' => 'x', 'remove' => ['a1']]],
            restrictions: [],
            rootType: 'author',
            rootIds: ['a1'],
        );

        $this->assertSame(['deleted' => ['author' => 1, 'book' => 2], 'detached' => ['chapter' => 1], 'restricted' => []], $plan->counts());
        $this->assertFalse($plan->restricted());
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/{,.}[!.,!..]*', GLOB_MARK | GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
