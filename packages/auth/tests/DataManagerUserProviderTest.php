<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthenticatableTrait;
use Preflow\Auth\DataManagerUserProvider;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;
use Preflow\Data\ModelMetadata;

#[Entity(table: 'users', storage: 'default')]
final class UserModel extends Model implements Authenticatable
{
    use AuthenticatableTrait;

    #[Id] public string $uuid = '';
    #[Field] public string $email = '';
    #[Field] public string $passwordHash = '';
    #[Field] public string $roles = '[]';

    public function getRoles(): array
    {
        return json_decode($this->roles, true) ?: [];
    }
}

final class DataManagerUserProviderTest extends TestCase
{
    private \PDO $pdo;
    private DataManager $dm;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE users (uuid TEXT PRIMARY KEY, email TEXT UNIQUE, passwordHash TEXT, roles TEXT DEFAULT "[]")');
        $this->dm = new DataManager(['default' => new SqliteDriver($this->pdo)]);
    }

    private function createUser(string $uuid, string $email, string $passwordHash = 'hash'): UserModel
    {
        $user = new UserModel();
        $user->uuid = $uuid;
        $user->email = $email;
        $user->passwordHash = $passwordHash;
        $user->roles = '[]';
        $this->dm->save($user);
        return $user;
    }

    public function test_find_by_id(): void
    {
        $this->createUser('user-1', 'alice@example.com', '$2y$10$testhash');

        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $found = $provider->findById('user-1');

        $this->assertInstanceOf(Authenticatable::class, $found);
        $this->assertSame('user-1', $found->getAuthId());
    }

    public function test_find_by_id_returns_null_for_missing(): void
    {
        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $found = $provider->findById('nonexistent');

        $this->assertNull($found);
    }

    public function test_find_by_credentials(): void
    {
        $this->createUser('user-2', 'bob@example.com', '$2y$10$bobhash');

        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $found = $provider->findByCredentials(['email' => 'bob@example.com']);

        $this->assertInstanceOf(Authenticatable::class, $found);
        $this->assertSame('user-2', $found->getAuthId());
    }

    public function test_find_by_credentials_returns_null_for_missing(): void
    {
        $provider = new DataManagerUserProvider($this->dm, UserModel::class);
        $found = $provider->findByCredentials(['email' => 'nobody@example.com']);

        $this->assertNull($found);
    }
}
