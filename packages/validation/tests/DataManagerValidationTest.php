<?php

declare(strict_types=1);

namespace Preflow\Validation\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\Attributes\Validate;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\Validation\ValidationException;

#[Entity(table: 'test_users', storage: 'default')]
class ValidatedUser extends Model
{
    #[Id]
    public int $id = 0;

    #[Field]
    #[Validate('required', 'min:2')]
    public string $name = '';

    #[Field]
    #[Validate('required', 'email')]
    public string $email = '';

    #[Field]
    #[Validate('nullable', 'integer')]
    public ?int $age = null;
}

final class DataManagerValidationTest extends TestCase
{
    private DataManager $dm;
    private \PDO $pdo;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE test_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT, age INTEGER)');

        $driver = new SqliteDriver($this->pdo);
        $validatorFactory = new ValidatorFactory(new RuleFactory());
        $this->dm = new DataManager(
            drivers: ['default' => $driver],
            validatorFactory: $validatorFactory,
        );
    }

    public function test_save_passes_with_valid_data(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = 30;

        $this->dm->save($user);
        $this->assertGreaterThan(0, $user->id);
    }

    public function test_save_throws_validation_exception_on_invalid_data(): void
    {
        $user = new ValidatedUser();
        $user->name = '';
        $user->email = 'not-an-email';

        $this->expectException(ValidationException::class);
        $this->dm->save($user);
    }

    public function test_validation_exception_carries_field_errors(): void
    {
        $user = new ValidatedUser();
        $user->name = 'A';
        $user->email = 'bad';

        try {
            $this->dm->save($user);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('name', $errors);
            $this->assertArrayHasKey('email', $errors);
        }
    }

    public function test_save_with_validate_false_skips_validation(): void
    {
        $user = new ValidatedUser();
        $user->name = '';
        $user->email = 'bad';

        $this->dm->save($user, validate: false);
        $this->assertGreaterThan(0, $user->id);
    }

    public function test_save_with_extra_rules(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = 200;

        $this->expectException(ValidationException::class);
        $this->dm->save($user, rules: ['age' => ['max:150']]);
    }

    public function test_insert_validates(): void
    {
        $user = new ValidatedUser();
        $user->name = '';
        $user->email = '';

        $this->expectException(ValidationException::class);
        $this->dm->insert($user);
    }

    public function test_update_validates(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $this->dm->save($user);

        $user->name = '';
        $this->expectException(ValidationException::class);
        $this->dm->update($user);
    }

    public function test_nullable_field_passes_with_null(): void
    {
        $user = new ValidatedUser();
        $user->name = 'Alice';
        $user->email = 'alice@example.com';
        $user->age = null;

        $this->dm->save($user);
        $this->assertGreaterThan(0, $user->id);
    }

    public function test_dynamic_record_validation(): void
    {
        $this->pdo->exec('CREATE TABLE events (uuid TEXT PRIMARY KEY, title TEXT, capacity INTEGER)');

        $tmpDir = sys_get_temp_dir() . '/preflow_val_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir . '/event.json', json_encode([
            'key' => 'event',
            'table' => 'events',
            'storage' => 'default',
            'fields' => [
                'title' => ['type' => 'string', 'searchable' => true, 'validate' => ['required', 'min:3']],
                'capacity' => ['type' => 'integer', 'validate' => ['required', 'integer']],
            ],
        ]));

        $registry = new TypeRegistry($tmpDir);
        $driver = new SqliteDriver($this->pdo);
        $dm = new DataManager(
            drivers: ['default' => $driver],
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        $typeDef = $registry->get('event');
        $record = new \Preflow\Data\DynamicRecord($typeDef, ['uuid' => 'e1', 'title' => '', 'capacity' => 'abc']);

        try {
            $dm->saveType($record);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('title', $e->errors());
            $this->assertArrayHasKey('capacity', $e->errors());
        }

        unlink($tmpDir . '/event.json');
        rmdir($tmpDir);
    }
}
