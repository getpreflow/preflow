<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Attributes\Timestamps;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

#[Entity(table: 'notes', storage: 'sqlite')]
class StringIdNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';
}

#[Entity(table: 'notes', storage: 'json')]
class StringIdJsonNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';
}

#[Entity(table: 'notes', storage: 'sqlite')]
class UninitializedIdNote extends Model
{
    #[Id]
    public string $uuid;

    #[Field]
    public string $title = '';
}

/** As `preflow make:model` writes it: the attribute on the createdAt property. */
#[Entity(table: 'stamped', storage: 'sqlite')]
class StampedNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';

    #[Timestamps]
    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}

/** As the data README shows it: the attribute on the class. */
#[Entity(table: 'stamped', storage: 'json')]
#[Timestamps]
class StampedJsonNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';

    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}

#[Entity(table: 'stamped', storage: 'sqlite')]
class UnstampedNote extends Model
{
    #[Id]
    public string $uuid = '';

    public ?\DateTimeImmutable $createdAt = null;
    public ?\DateTimeImmutable $updatedAt = null;
}

final class ModelIdsAndTimestampsTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $jsonDir;
    private DataManager $dm;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE notes (uuid TEXT PRIMARY KEY, title TEXT)');
        $pdo->exec('CREATE TABLE stamped (uuid TEXT PRIMARY KEY, title TEXT, createdAt TEXT, updatedAt TEXT)');
        $this->jsonDir = sys_get_temp_dir() . '/preflow_ids_test_' . uniqid();
        mkdir($this->jsonDir, 0755, true);
        $this->dm = new DataManager(['sqlite' => new SqliteDriver($pdo), 'json' => new JsonFileDriver($this->jsonDir)]);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->jsonDir));
    }

    /** @return iterable<string, array{class-string<Model>, string}> */
    public static function writes(): iterable
    {
        foreach (['insert', 'save'] as $method) {
            yield "sqlite {$method}" => [StringIdNote::class, $method];
            yield "json {$method}" => [StringIdJsonNote::class, $method];
            yield "uninitialized {$method}" => [UninitializedIdNote::class, $method];
        }
    }

    /** @param class-string<Model> $class */
    #[DataProvider('writes')]
    public function test_a_string_id_empty_on_insert_gets_a_uuid_v4(string $class, string $method): void
    {
        $note = new $class();
        $note->title = 'Hello';
        $this->dm->{$method}($note);

        $this->assertMatchesRegularExpression(self::UUID_V4, $note->uuid);
        $found = $this->dm->find($class, $note->uuid);
        $this->assertNotNull($found);
        $this->assertSame('Hello', $found->title);

        $other = new $class();
        $other->title = 'Other';
        $this->dm->{$method}($other);
        $this->assertNotSame($note->uuid, $other->uuid);
    }

    public function test_a_given_string_id_is_kept(): void
    {
        $note = new StringIdNote();
        $note->uuid = 'mine';
        $this->dm->insert($note);

        $this->assertSame('mine', $note->uuid);
        $this->assertNotNull($this->dm->find(StringIdNote::class, 'mine'));
    }

    /** @return iterable<string, array{class-string<Model>}> */
    public static function stamped(): iterable
    {
        yield 'property attribute, sqlite' => [StampedNote::class];
        yield 'class attribute, json' => [StampedJsonNote::class];
    }

    /** @param class-string<StampedNote|StampedJsonNote> $class */
    #[DataProvider('stamped')]
    public function test_timestamps_are_set_on_create_and_update(string $class): void
    {
        $before = new \DateTimeImmutable('-1 second');
        $note = new $class();
        $note->title = 'One';
        $this->dm->insert($note);

        $this->assertInstanceOf(\DateTimeImmutable::class, $note->createdAt);
        $this->assertInstanceOf(\DateTimeImmutable::class, $note->updatedAt);
        $this->assertGreaterThanOrEqual($before, $note->createdAt);
        $created = $note->createdAt->format('Y-m-d H:i:s');

        $found = $this->dm->find($class, $note->uuid);
        $this->assertSame($created, $found->createdAt?->format('Y-m-d H:i:s'));

        $found->createdAt = new \DateTimeImmutable('2020-01-02 03:04:05');
        $found->updatedAt = new \DateTimeImmutable('2020-01-02 03:04:05');
        $found->title = 'Two';
        $this->dm->update($found);

        $this->assertSame('2020-01-02 03:04:05', $found->createdAt->format('Y-m-d H:i:s'), 'created is kept');
        $this->assertGreaterThanOrEqual($before, $found->updatedAt, 'updated is set again');

        $again = $this->dm->find($class, $note->uuid);
        $this->assertSame('2020-01-02 03:04:05', $again->createdAt?->format('Y-m-d H:i:s'));
        $this->assertGreaterThanOrEqual($before->format('Y-m-d H:i:s'), $again->updatedAt?->format('Y-m-d H:i:s'));

        $again->title = 'Three';
        $again->updatedAt = new \DateTimeImmutable('2020-01-02 03:04:05');
        $this->dm->save($again);
        $this->assertGreaterThanOrEqual($before, $again->updatedAt, 'save() sets updated too');
    }

    public function test_a_model_without_the_attribute_is_not_stamped(): void
    {
        $note = new UnstampedNote();
        $this->dm->insert($note);

        $this->assertNull($note->createdAt);
        $this->assertNull($note->updatedAt);
    }
}
