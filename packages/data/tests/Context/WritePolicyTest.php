<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Context;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\SkippedWrite;
use Preflow\Core\Context\WritesRefused;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\MysqlDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Model;
use Preflow\Data\Query;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Testing\ContextFactory;
use Preflow\Testing\MysqlTestDatabase;

#[Entity(table: 'notes', storage: 'default')]
class PolicyNote extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    public string $body = '';
}

/**
 * The write policy on every DataManager write entry point: under Skip nothing is written, each
 * write is reported once as a SkippedWrite and the entry point returns as if it had written
 * (ruling R9); under Refuse nothing is written and WritesRefused is thrown; outside a render
 * (no frame) everything is written. On SQLite, JSON files and (group mysql) MySQL.
 */
final class WritePolicyTest extends TestCase
{
    private string $dir;

    private ?\PDO $mysql = null;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->dir = sys_get_temp_dir() . '/preflow_write_policy_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        mkdir($this->dir . '/json', 0777, true);
        file_put_contents($this->dir . '/models/item.json', json_encode([
            'key' => 'item',
            'table' => 'items',
            'storage' => 'default',
            'fields' => [
                'title' => ['type' => 'string'],
                'hits' => ['type' => 'integer'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        if ($this->mysql !== null) {
            MysqlTestDatabase::dropAllTables($this->mysql);
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** A manager on $kind holding the item i1 (title One, version 1, 5 hits) and the note 1. */
    private function manager(string $kind): DataManager
    {
        $driver = match ($kind) {
            'json' => new JsonFileDriver($this->dir . '/json'),
            'sqlite' => new SqliteDriver($this->sqlitePdo()),
            'mysql' => new MysqlDriver($this->mysqlPdo()),
        };
        $dm = new DataManager(['default' => $driver], 'default', new TypeRegistry($this->dir . '/models'));
        $dm->saveType(new DynamicRecord($this->item($dm), ['uuid' => 'i1', 'title' => 'One', 'hits' => 5, '_version' => 1]), validate: false);
        $dm->insert(self::note(1, 'one'));

        return $dm;
    }

    private function sqlitePdo(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE items (uuid TEXT PRIMARY KEY, title TEXT, hits INTEGER, _version INTEGER)');
        $pdo->exec('CREATE TABLE notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT)');

        return $pdo;
    }

    private function mysqlPdo(): \PDO
    {
        $pdo = MysqlTestDatabase::pdo();
        MysqlTestDatabase::dropAllTables($pdo);
        $this->mysql = $pdo;
        $pdo->exec('CREATE TABLE items (uuid VARCHAR(64) PRIMARY KEY, title VARCHAR(255), hits INT, _version INT)');
        $pdo->exec('CREATE TABLE notes (id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(255))');

        return $pdo;
    }

    private function item(DataManager $dm): TypeDefinition
    {
        return $dm->getTypeRegistry()->get('item');
    }

    /** Everything stored, to compare before and after. */
    private function stored(DataManager $dm): array
    {
        $rows = [];
        foreach (['items' => 'uuid', 'notes' => 'id'] as $table => $id) {
            $list = array_map(
                static fn (array $row): array => array_map(static fn (mixed $v): mixed => is_int($v) ? (string) $v : $v, $row),
                $dm->driver('default')->findMany($table, new Query())->items(),
            );
            usort($list, static fn (array $a, array $b): int => strcmp((string) $a[$id], (string) $b[$id]));
            $rows[$table] = $list;
        }

        return $rows;
    }

    private static function note(int $id, string $body): PolicyNote
    {
        $note = new PolicyNote();
        $note->id = $id;
        $note->body = $body;

        return $note;
    }

    /**
     * Every write entry point: name => [write, what it returns when skipped, skipped writes as
     * "operation table id"]. The writes would change the store if they ran.
     *
     * @return array<string, array{\Closure(DataManager, TypeDefinition): mixed, mixed, list<string>}>
     */
    private static function entryPoints(): array
    {
        return [
            'save (new model)' => [static fn (DataManager $dm) => $dm->save(self::note(0, 'new')), null, ['save notes -']],
            'save (stored model)' => [static fn (DataManager $dm) => $dm->save(self::note(1, 'changed')), null, ['save notes 1']],
            'insert' => [static fn (DataManager $dm) => $dm->insert(self::note(0, 'new')), null, ['save notes -']],
            'insert (a given int id)' => [static fn (DataManager $dm) => $dm->insert(self::note(7, 'new')), null, ['save notes 7']],
            'insert (a stored id: as if written)' => [static fn (DataManager $dm) => $dm->insert(self::note(1, 'again')), null, ['save notes 1']],
            'insertIgnoring' => [static fn (DataManager $dm) => $dm->insertIgnoring(self::note(8, 'new')), true, ['save notes 8']],
            'insertIgnoring (a stored id: as if written)' => [static fn (DataManager $dm) => $dm->insertIgnoring(self::note(1, 'again')), true, ['save notes 1']],
            'insertMany' => [static fn (DataManager $dm) => $dm->insertMany([self::note(8, 'a'), self::note(9, 'b')]), 2, ['save notes 8', 'save notes 9']],
            'insertMany (ignoring duplicates)' => [static fn (DataManager $dm) => $dm->insertMany([self::note(1, 'a'), self::note(9, 'b')], true), 2, ['save notes 1', 'save notes 9']],
            'update' => [static fn (DataManager $dm) => $dm->update(self::note(1, 'changed')), null, ['save notes 1']],
            'delete (model)' => [static fn (DataManager $dm) => $dm->delete(self::note(1, 'one')), null, ['delete notes 1']],
            'delete (class and id)' => [static fn (DataManager $dm) => $dm->delete(PolicyNote::class, 1), null, ['delete notes 1']],
            'saveType (new)' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->saveType(new DynamicRecord($t, ['uuid' => 'i2', 'title' => 'Two'])), null, ['save items i2']],
            'saveType (stored)' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->saveType(new DynamicRecord($t, ['uuid' => 'i1', 'title' => 'Changed'])), null, ['save items i1']],
            'saveTypeIfVersion' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->saveTypeIfVersion(new DynamicRecord($t, ['uuid' => 'i1', 'title' => 'Changed']), 1), true, ['save items i1']],
            'saveTypeIfVersion (another version: as if written)' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->saveTypeIfVersion(new DynamicRecord($t, ['uuid' => 'i1', 'title' => 'Changed']), 4), true, ['save items i1']],
            'updateFieldsIfVersion' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->updateFieldsIfVersion($t, 'i1', ['title' => 'Changed'], '_version', 1), true, ['save items i1']],
            'deleteType' => [static fn (DataManager $dm) => $dm->deleteType('item', 'i1'), null, ['delete items i1']],
            'deleteIn' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->deleteIn($t, 'i1'), null, ['delete items i1']],
            'deleteManyIn' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->deleteManyIn($t, ['i1', 'i9', 'i1']), null, ['delete items i1', 'delete items i9']],
            'executeDeletePlan' => [static fn (DataManager $dm) => $dm->executeDeletePlan($dm->deletePlan('item', ['i1'])), null, ['delete items i1']],
            'incrementIn (stored value unchanged)' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->incrementIn($t, 'i1', 'hits', 1, []), 5, ['increment items i1']],
            'incrementIn (a new row: nothing stored)' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->incrementIn($t, 'i9', 'hits', 1, ['title' => 'Nine']), 0, ['increment items i9']],
            'transaction (its writes follow the policy)' => [static fn (DataManager $dm, TypeDefinition $t) => $dm->transaction(['default'], static function () use ($dm, $t): string {
                $dm->saveType(new DynamicRecord($t, ['uuid' => 'i2', 'title' => 'Two']));
                $dm->delete(PolicyNote::class, 1);

                return 'done';
            }), 'done', ['save items i2', 'delete notes 1']],
        ];
    }

    /** @return array<string, array{\Closure(DataManager, TypeDefinition): mixed, mixed, list<string>}> */
    private static function sqlEntryPoints(): array
    {
        return [
            'execute' => [static fn (DataManager $dm) => $dm->execute('UPDATE items SET title = ?', ['Changed']), 0, ['raw items -']],
            'raw (a write)' => [static fn (DataManager $dm) => $dm->raw('DELETE FROM notes WHERE id = ?', [1]), [], ['raw notes -']],
        ];
    }

    /** @return iterable<string, array{string, string}> */
    public static function writes(): iterable
    {
        foreach (['sqlite', 'json'] as $kind) {
            foreach (array_keys(self::entryPoints()) as $name) {
                yield "{$kind}: {$name}" => [$kind, $name];
            }
        }
        foreach (array_keys(self::sqlEntryPoints()) as $name) {
            yield "sqlite: {$name}" => ['sqlite', $name];
        }
    }

    /** @return list<string> "operation table id" per skipped write */
    private static function described(array $writes): array
    {
        return array_map(static fn (SkippedWrite $w): string => "{$w->operation} {$w->type} " . ($w->id ?? '-'), $writes);
    }

    private static function skipping(): RenderContext
    {
        return ContextFactory::forTest()->skippingWrites();
    }

    #[DataProvider('writes')]
    public function test_every_write_entry_point_follows_the_policy(string $kind, string $name): void
    {
        $this->assertEntryPointFollowsThePolicy($kind, $name);
    }

    #[Group('mysql')]
    public function test_every_write_entry_point_follows_the_policy_on_mysql(): void
    {
        foreach (array_keys([...self::entryPoints(), ...self::sqlEntryPoints()]) as $name) {
            $this->assertEntryPointFollowsThePolicy('mysql', $name);
        }
    }

    private function assertEntryPointFollowsThePolicy(string $kind, string $name): void
    {
        [$write, $returns, $reported] = [...self::entryPoints(), ...self::sqlEntryPoints()][$name];
        $dm = $this->manager($kind);
        $before = $this->stored($dm);

        $frame = null;
        $result = Contexts::run(self::skipping(), function () use ($dm, $write, &$frame): mixed {
            $frame = Contexts::frame();

            return $write($dm, $this->item($dm));
        });
        $this->assertSame($returns, $result, "{$name}: returns as if written");
        $this->assertSame($before, $this->stored($dm), "{$name}: nothing written under Skip");
        $this->assertSame($reported, self::described($frame->skippedWrites()), "{$name}: each write reported once");
        $this->assertSame(['default'], array_values(array_unique(array_map(static fn (SkippedWrite $w): string => $w->storage, $frame->skippedWrites()))));

        try {
            Contexts::run(ContextFactory::forTest()->refusingWrites(), fn (): mixed => $write($dm, $this->item($dm)));
            $this->fail("{$name}: refused");
        } catch (WritesRefused $e) {
            $this->assertSame($reported[0], self::described([$e->write])[0], "{$name}: the refusal names the write");
        }
        $this->assertSame($before, $this->stored($dm), "{$name}: nothing written under Refuse");
        $this->assertSame(0, Contexts::depth());

        if (str_contains($name, 'as if written')) {
            return; // outside a render these take the duplicate or lost-race path and write nothing
        }
        // Outside a render the same write runs (R1).
        $write($dm, $this->item($dm));
        $this->assertNotSame($before, $this->stored($dm), "{$name}: outside a render it writes");
    }

    public function test_skipped_save_leaves_the_store_and_is_reported(): void
    {
        $dm = $this->manager('sqlite');
        $frame = null;
        Contexts::run(self::skipping(), function () use ($dm, &$frame): void {
            $frame = Contexts::frame();
            $dm->saveType(new DynamicRecord($this->item($dm), ['uuid' => 'i2', 'title' => 'Two']));
        });

        $this->assertNull($dm->findType('item', 'i2'));
        $this->assertEquals([new SkippedWrite('default', 'items', 'i2', 'save')], $frame->skippedWrites());
    }

    public function test_refused_save_throws_and_leaves_the_store(): void
    {
        $dm = $this->manager('sqlite');

        try {
            Contexts::run(ContextFactory::forTest()->refusingWrites(), fn () => $dm->saveType(new DynamicRecord($this->item($dm), ['uuid' => 'i2', 'title' => 'Two'])));
            $this->fail('refused');
        } catch (WritesRefused $e) {
            $this->assertEquals(new SkippedWrite('default', 'items', 'i2', 'save'), $e->write);
            $this->assertSame('This preview does not change data.', $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e);
        }
        $this->assertNull($dm->findType('item', 'i2'));
    }

    public function test_writes_outside_a_frame_run(): void
    {
        $dm = $this->manager('sqlite');
        $this->assertNull(Contexts::peek());

        $dm->saveType(new DynamicRecord($this->item($dm), ['uuid' => 'i2', 'title' => 'Two']));

        $this->assertSame('Two', $dm->findType('item', 'i2')?->get('title'));
    }

    public function test_writes_under_allow_run_and_report_nothing(): void
    {
        $dm = $this->manager('sqlite');
        $frame = null;
        Contexts::run(ContextFactory::forTest(), function () use ($dm, &$frame): void {
            $frame = Contexts::frame();
            $dm->saveType(new DynamicRecord($this->item($dm), ['uuid' => 'i2', 'title' => 'Two']));
        });

        $this->assertSame('Two', $dm->findType('item', 'i2')?->get('title'));
        $this->assertSame([], $frame->skippedWrites());
    }

    public function test_reads_are_unaffected_by_the_policy(): void
    {
        $dm = $this->manager('sqlite');
        $frame = null;
        $read = Contexts::run(ContextFactory::forTest()->refusingWrites(), function () use ($dm, &$frame): array {
            $frame = Contexts::frame();

            return [
                $dm->findType('item', 'i1')?->get('title'),
                $dm->find(PolicyNote::class, 1)?->body,
                count($dm->queryType('item')->get()->items()),
                $dm->raw('SELECT title FROM items'),
            ];
        });

        $this->assertSame(['One', 'one', 1, [['title' => 'One']]], $read);
        $this->assertSame([], $frame->skippedWrites());
    }

    public function test_skipped_delete_execute_and_increment_report_their_operation(): void
    {
        $dm = $this->manager('sqlite');
        $frame = null;
        $results = Contexts::run(self::skipping(), function () use ($dm, &$frame): array {
            $frame = Contexts::frame();

            return [
                $dm->deleteIn($this->item($dm), 'i1'),
                $dm->execute('DELETE FROM notes'),
                $dm->incrementIn($this->item($dm), 'i1', 'hits', 3, []),
            ];
        });

        $this->assertSame([null, 0, 5], $results);
        $this->assertEquals([
            new SkippedWrite('default', 'items', 'i1', 'delete'),
            new SkippedWrite('default', 'notes', null, 'raw'),
            new SkippedWrite('default', 'items', 'i1', 'increment'),
        ], $frame->skippedWrites());
        $this->assertSame(5, (int) $dm->findType('item', 'i1')?->get('hits'));
        $this->assertNotNull($dm->find(PolicyNote::class, 1));
    }
}
