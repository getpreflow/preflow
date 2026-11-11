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
use Preflow\Data\Locales;
use Preflow\Data\Migration\Schema;
use Preflow\Data\Migration\Table;
use Preflow\Data\ModelMetadata;
use Preflow\Data\TypeRegistry;

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

/** As the data README shows it: the class attribute, snake_case properties, a timestamps() table. */
#[Entity(table: 'snake_stamped', storage: 'sqlite')]
#[Timestamps]
class SnakeStampedNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';

    public ?string $created_at = null;
    public ?string $updated_at = null;
}

/** A class-backed type: it stores what its type says, #[Timestamps] does not stamp it. */
#[Entity(table: 'stamped_type', storage: 'sqlite', type: 'stamped_type')]
#[Timestamps]
class StampedTypeNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public ?string $title = null;

    public ?string $createdAt = null;
    public ?string $updatedAt = null;
}

/** A mutable \DateTime can't be stored (no transformer), so #[Timestamps] leaves it alone. */
#[Entity(table: 'stamped', storage: 'sqlite')]
#[Timestamps]
class MutableStampedNote extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public string $title = '';

    public ?\DateTime $createdAt = null;
    public ?\DateTime $updatedAt = null;
}

final class ModelIdsAndTimestampsTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private string $jsonDir;
    private DataManager $dm;
    private \PDO $pdo;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE notes (uuid TEXT PRIMARY KEY, title TEXT)');
        $pdo->exec('CREATE TABLE stamped (uuid TEXT PRIMARY KEY, title TEXT, createdAt TEXT, updatedAt TEXT)');
        (new Schema($pdo))->create('snake_stamped', function (Table $t): void {
            $t->uuid('uuid')->primary();
            $t->string('title');
            $t->timestamps();
        });
        $pdo->exec('CREATE TABLE stamped_type (uuid TEXT PRIMARY KEY, title TEXT, createdAt TEXT, updatedAt TEXT)');
        $this->pdo = $pdo;
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

    public function test_snake_case_timestamps_on_a_timestamps_table_are_set_and_read_back(): void
    {
        $before = (new \DateTimeImmutable('-1 second'))->format('Y-m-d H:i:s');
        $note = new SnakeStampedNote();
        $note->title = 'One';
        $this->dm->insert($note);

        $this->assertNotNull($note->created_at);
        $this->assertNotNull($note->updated_at);
        $this->assertGreaterThanOrEqual($before, $note->created_at);
        $found = $this->dm->find(SnakeStampedNote::class, $note->uuid);
        $this->assertSame($note->created_at, $found->created_at);
        $this->assertSame($note->updated_at, $found->updated_at);

        $found->created_at = '2020-01-02 03:04:05';
        $found->updated_at = '2020-01-02 03:04:05';
        $this->dm->update($found);

        $again = $this->dm->find(SnakeStampedNote::class, $note->uuid);
        $this->assertSame('2020-01-02 03:04:05', $again->created_at, 'created is kept');
        $this->assertGreaterThanOrEqual($before, $again->updated_at, 'updated is set again');
    }

    public function test_a_class_backed_type_is_not_stamped(): void
    {
        $dir = sys_get_temp_dir() . '/preflow_stamped_type_' . uniqid();
        mkdir($dir);
        try {
            $types = new TypeRegistry($dir, new Locales(['en'], 'en'), ['stamped_type' => StampedTypeNote::class]);
            $dm = new DataManager(['sqlite' => new SqliteDriver($this->pdo)], 'sqlite', $types);
            $note = new StampedTypeNote();
            $note->title = 'Typed';
            $dm->insert($note);

            $this->assertNull($note->createdAt);
            $this->assertNull($note->updatedAt);
            $this->assertSame([null, null], array_values($this->pdo->query('SELECT createdAt, updatedAt FROM stamped_type')->fetch(\PDO::FETCH_NUM)));
        } finally {
            rmdir($dir);
        }
    }

    public function test_a_mutable_datetime_property_is_not_stamped(): void
    {
        $note = new MutableStampedNote();
        $note->title = 'Mutable';
        $this->dm->insert($note);

        $this->assertNull($note->createdAt);
        $this->assertNull($note->updatedAt);
        $this->assertSame(['Mutable', null, null], array_values($this->pdo->query('SELECT title, createdAt, updatedAt FROM stamped')->fetch(\PDO::FETCH_NUM)));
    }
}
