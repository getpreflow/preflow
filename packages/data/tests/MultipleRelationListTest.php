<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;

/**
 * A multiple relation stores its ids as a list (0.89): a JSON file holds a native array, SQL
 * storage the same JSON text as before. A single relation keeps one id. Values stored as JSON
 * text before 0.89 are still read, found by HAS and detached; a detach writes the list.
 */
final class MultipleRelationListTest extends TestCase
{
    private string $dir;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_relation_list_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return iterable<string, array{string}> */
    public static function storages(): iterable
    {
        yield 'json' => ['json'];
        yield 'sqlite' => ['sqlite'];
    }

    private function dm(string $storage): DataManager
    {
        foreach (['author' => ['name' => ['type' => 'string']], 'book' => [
            'title' => ['type' => 'string'],
            'authors' => ['type' => 'relation', 'relation' => ['to' => 'author', 'multiple' => true]],
            'editor' => ['type' => 'relation', 'relation' => ['to' => 'author']],
        ]] as $key => $fields) {
            file_put_contents($this->dir . "/models/{$key}.json", (string) json_encode([
                'key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid', 'fields' => $fields,
            ]));
        }
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE author (uuid TEXT PRIMARY KEY, name TEXT)');
        $this->pdo->exec('CREATE TABLE book (uuid TEXT PRIMARY KEY, title TEXT, authors TEXT, editor TEXT)');

        return new DataManager(
            ['json' => new JsonFileDriver($this->dir . '/data'), 'sqlite' => new SqliteDriver($this->pdo)],
            typeRegistry: new TypeRegistry($this->dir . '/models'),
        );
    }

    /** @param array<string, mixed> $data */
    private function save(DataManager $dm, string $type, array $data): void
    {
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), $data), false);
    }

    /** A book row as stored: the decoded JSON file, or the SQL row. @return array<string, mixed> */
    private function stored(string $storage, string $id): array
    {
        if ($storage === 'json') {
            return json_decode((string) file_get_contents($this->dir . "/data/book/{$id}.json"), true);
        }

        return $this->pdo->query("SELECT * FROM book WHERE uuid = '{$id}'")->fetch(\PDO::FETCH_ASSOC);
    }

    /** A value stored before 0.89, written past DataManager: JSON text in the file or the column. */
    private function storeOld(DataManager $dm, string $storage, string $id, string $authors): void
    {
        $dm->driver($storage)->save('book', $id, ['uuid' => $id, 'title' => 'Old', 'authors' => $authors, 'editor' => 'a1']);
    }

    /** @return list<string> */
    private static function ids(mixed $value): array
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }

    public function test_a_json_file_holds_the_list_sql_the_same_json_text_as_before(): void
    {
        $json = $this->dm('json');
        $this->save($json, 'book', ['uuid' => 'b1', 'title' => 'One', 'authors' => ['a1', 'a2'], 'editor' => 'a1']);
        $this->save($json, 'book', ['uuid' => 'b2', 'title' => 'Two', 'authors' => '["a2"]', 'editor' => 'a2']);
        $this->assertSame(['a1', 'a2'], $this->stored('json', 'b1')['authors']);
        $this->assertSame(['a2'], $this->stored('json', 'b2')['authors'], 'JSON text given becomes a list');
        $this->assertSame('a1', $this->stored('json', 'b1')['editor'], 'a single relation keeps one id');

        $sql = $this->dm('sqlite');
        $this->save($sql, 'book', ['uuid' => 'b1', 'title' => 'One', 'authors' => ['a1', 'a2'], 'editor' => 'a1']);
        $this->save($sql, 'book', ['uuid' => 'b2', 'title' => 'Two', 'authors' => '["a2"]', 'editor' => 'a2']);
        $this->assertSame('["a1","a2"]', $this->stored('sqlite', 'b1')['authors']);
        $this->assertSame('["a2"]', $this->stored('sqlite', 'b2')['authors']);
        $this->assertSame('a1', $this->stored('sqlite', 'b1')['editor']);
    }

    #[DataProvider('storages')]
    public function test_old_json_text_and_new_lists_are_read_and_found_together(string $storage): void
    {
        $dm = $this->dm($storage);
        $this->storeOld($dm, $storage, 'old', '["a1","a3"]');
        $this->save($dm, 'book', ['uuid' => 'new', 'title' => 'New', 'authors' => ['a1', 'a2'], 'editor' => 'a2']);

        $this->assertSame(['a1', 'a3'], self::ids($dm->findType('book', 'old')?->get('authors')));
        $this->assertSame(['a1', 'a2'], self::ids($dm->findType('book', 'new')?->get('authors')));

        $has = function (array $ids) use ($dm): array {
            $found = array_map(
                static fn (DynamicRecord $r): string => (string) $r->getId(),
                $dm->queryType('book')->whereAny([['field' => 'authors', 'operator' => 'HAS', 'value' => $ids]])->get()->items(),
            );
            sort($found);

            return $found;
        };
        $this->assertSame(['new', 'old'], $has(['a1']));
        $this->assertSame(['old'], $has(['a3']));
        $this->assertSame(['new'], $has(['a2']));
    }

    #[DataProvider('storages')]
    public function test_a_detach_from_old_json_text_writes_the_list(string $storage): void
    {
        $dm = $this->dm($storage);
        $this->save($dm, 'author', ['uuid' => 'a1', 'name' => 'A']);
        $this->save($dm, 'author', ['uuid' => 'a2', 'name' => 'B']);
        $this->storeOld($dm, $storage, 'old', '["a2","a1"]');

        $plan = $dm->deletePlan('author', ['a1']);
        $this->assertSame(['book' => 1], $plan->counts()['detached']);
        $dm->executeDeletePlan($plan);

        $stored = $this->stored($storage, 'old');
        $this->assertSame($storage === 'json' ? ['a2'] : '["a2"]', $stored['authors'], 'a list in a JSON file, JSON text in SQL');
        $this->assertSame('', $stored['editor']);
    }
}
