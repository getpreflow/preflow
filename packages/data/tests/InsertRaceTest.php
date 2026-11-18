<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\InsertsNew;
use Preflow\Data\Locales;
use Preflow\Data\Model;
use Preflow\Data\Query;
use Preflow\Data\ResultSet;
use Preflow\Data\StorageDriver;
use Preflow\Data\TypeRegistry;

#[Entity(table: 'race_events', storage: 'default')]
class RaceEvent extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';
}

#[Entity(table: 'race_typed', storage: 'default', type: 'race_typed')]
class RaceTypedEvent extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public ?string $title = null;
}

/**
 * A driver that lets a second writer in right after insert() has checked that the id is free,
 * the way a second request does.
 */
final class RacingDriver implements StorageDriver, InsertsNew
{
    public ?\Closure $afterCheck = null;

    public function __construct(private readonly StorageDriver&InsertsNew $inner) {}

    private function raced(): void
    {
        $hook = $this->afterCheck;
        $this->afterCheck = null;
        $hook?->__invoke();
    }

    public function findOne(string $type, string|int $id, string $idField = 'uuid'): ?array
    {
        $row = $this->inner->findOne($type, $id, $idField);
        $this->raced();

        return $row;
    }

    public function exists(string $type, string|int $id, string $idField = 'uuid'): bool
    {
        $exists = $this->inner->exists($type, $id, $idField);
        $this->raced();

        return $exists;
    }

    public function findMany(string $type, Query $query): ResultSet
    {
        return $this->inner->findMany($type, $query);
    }

    public function save(string $type, string|int $id, array $data, string $idField = 'uuid'): void
    {
        $this->inner->save($type, $id, $data, $idField);
    }

    public function insertNew(string $type, array $data, string $idField = 'uuid'): bool
    {
        return $this->inner->insertNew($type, $data, $idField);
    }

    public function delete(string $type, string|int $id, string $idField = 'uuid'): void
    {
        $this->inner->delete($type, $id, $idField);
    }

    public function lastInsertId(): string|int
    {
        return $this->inner->lastInsertId();
    }
}

final class InsertRaceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_insert_race_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/json', 0755, true);
        mkdir($this->dir . '/types', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }
            is_dir($entry) ? $this->remove($entry) : unlink($entry);
        }
        @rmdir($path);
    }

    /** @return StorageDriver&InsertsNew */
    private function storage(string $kind): StorageDriver
    {
        if ($kind === 'json') {
            return new JsonFileDriver($this->dir . '/json');
        }
        $pdo = new \PDO('sqlite:' . $this->dir . '/db.sqlite');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS race_events (uuid TEXT PRIMARY KEY, title TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS race_typed (uuid TEXT PRIMARY KEY, title TEXT)');

        return new SqliteDriver($pdo);
    }

    private function manager(StorageDriver $driver): DataManager
    {
        return new DataManager(['default' => $driver], 'default', new TypeRegistry($this->dir . '/types', new Locales(['en'], 'en'), ['race_typed' => RaceTypedEvent::class]));
    }

    /** @return iterable<string, array{string, class-string<Model>, string}> */
    public static function cases(): iterable
    {
        foreach (['sqlite', 'json'] as $kind) {
            yield "plain model, {$kind}" => [$kind, RaceEvent::class, 'race_events'];
            yield "class-backed type, {$kind}" => [$kind, RaceTypedEvent::class, 'race_typed'];
        }
    }

    /** @param class-string<Model> $class */
    #[DataProvider('cases')]
    public function test_a_second_writer_between_the_check_and_the_write_is_a_duplicate_error_not_an_overwrite(string $kind, string $class, string $table): void
    {
        $racing = new RacingDriver($this->storage($kind));
        $mine = $this->manager($racing);
        $theirs = $this->manager($this->storage($kind));

        $racing->afterCheck = function () use ($theirs, $class): void {
            $other = new $class();
            $other->uuid = 'same';
            $other->title = 'Theirs';
            $theirs->insert($other);
        };

        $model = new $class();
        $model->uuid = 'same';
        $model->title = 'Mine';

        try {
            $mine->insert($model);
            $this->fail('insert() overwrote the record of the other writer.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('a record with this id exists', $e->getMessage());
        }

        $this->assertSame('Theirs', $this->storage($kind)->findOne($table, 'same')['title'] ?? null, 'the first row is unchanged');
    }

    /** @param class-string<Model> $class */
    #[DataProvider('cases')]
    public function test_insert_ignoring_loses_the_race_quietly(string $kind, string $class, string $table): void
    {
        $racing = new RacingDriver($this->storage($kind));
        $mine = $this->manager($racing);
        $theirs = $this->manager($this->storage($kind));

        $racing->afterCheck = function () use ($theirs, $class): void {
            $other = new $class();
            $other->uuid = 'same';
            $other->title = 'Theirs';
            $theirs->insert($other);
        };

        $model = new $class();
        $model->uuid = 'same';
        $model->title = 'Mine';

        $this->assertFalse($mine->insertIgnoring($model));
        $this->assertSame('Theirs', $this->storage($kind)->findOne($table, 'same')['title'] ?? null);
    }
}
