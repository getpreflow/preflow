<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ArrayUser;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\PasswordRehash;
use Preflow\Auth\Record\RecordUser;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\UpdatesPasswordHash;
use Preflow\Auth\UserProviderInterface;
use Preflow\Auth\UserProviderUnavailable;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/** Record users in auth, and a sign-in that upgrades an outdated password hash. */
final class PasswordRehashTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery';

    private string $dir;
    private DataManager $dm;
    private TypeDefinition $member;
    private NativePasswordHasher $hasher;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rehash_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE member (uuid TEXT PRIMARY KEY, email TEXT, password_hash TEXT, roles TEXT, auth_epoch TEXT)');
        $registry = new TypeRegistry($this->dir . '/models');
        // Defined in code, as an extension's type is: not in the models path.
        $this->member = $registry->define([
            'key' => 'member', 'table' => 'member', 'storage' => 'sqlite', 'id_field' => 'uuid',
            'fields' => [
                'email' => ['type' => 'string'], 'password_hash' => ['type' => 'string'],
                'roles' => ['type' => 'string'], 'auth_epoch' => ['type' => 'string'],
            ],
        ]);
        $this->dm = new DataManager(drivers: ['sqlite' => new SqliteDriver($pdo)], defaultDriver: 'sqlite', typeRegistry: $registry);
        $this->hasher = new NativePasswordHasher();
    }

    protected function tearDown(): void
    {
        @rmdir($this->dir . '/models');
        @rmdir($this->dir);
    }

    private function save(string $id, string $hash, string $epoch = '3'): void
    {
        $this->dm->saveType(new DynamicRecord($this->member, [
            'uuid' => $id, 'email' => $id . '@example.com', 'password_hash' => $hash, 'roles' => '[]', 'auth_epoch' => $epoch,
        ]), false);
    }

    private function stored(string $id, string $field = 'password_hash'): string
    {
        return (string) $this->dm->findType('member', $id)?->get($field);
    }

    private function provider(?\Closure $types = null): RecordUserProvider
    {
        return new RecordUserProvider($this->dm, ['type' => 'member'], $types ?? fn (string $type): ?TypeDefinition => $type === 'member' ? $this->member : null);
    }

    private static function md5Crypt(string $password): string
    {
        return crypt($password, '$1$' . substr(bin2hex(random_bytes(4)), 0, 8) . '$');
    }

    /** Signs in as a login does: find, verify, then rehash. */
    private function signIn(UserProviderInterface $provider, string $email): Authenticatable
    {
        $user = $provider->findByCredentials(['email' => $email]);
        $this->assertNotNull($user);
        $this->assertTrue($this->hasher->verify(self::PASSWORD, $user->getPasswordHash()));
        PasswordRehash::afterVerify($provider, $user, self::PASSWORD, $this->hasher);

        return $user;
    }

    public function test_an_md5_crypt_hash_is_upgraded_on_sign_in(): void
    {
        $old = self::md5Crypt(self::PASSWORD);
        $this->assertStringStartsWith('$1$', $old);
        $this->assertTrue($this->hasher->needsRehash($old));
        $this->save('m1', $old);

        $this->signIn($this->provider(), 'm1@example.com');

        $new = $this->stored('m1');
        $this->assertNotSame($old, $new);
        $this->assertFalse($this->hasher->needsRehash($new), 'a hash needsRehash() accepts');
        $this->assertTrue($this->hasher->verify(self::PASSWORD, $new));
    }

    public function test_the_auth_epoch_is_unchanged_so_the_new_session_survives(): void
    {
        $this->save('m1', self::md5Crypt(self::PASSWORD), epoch: '3');
        $user = $this->signIn($this->provider(), 'm1@example.com');

        $this->assertSame('3', $this->stored('m1', 'auth_epoch'));
        $fresh = $this->provider()->findById('m1');
        $this->assertSame($user->getSessionEpoch(), $fresh?->getSessionEpoch());
    }

    public function test_a_current_hash_is_left_alone(): void
    {
        $hash = $this->hasher->hash(self::PASSWORD);
        $this->save('m1', $hash);

        $this->signIn($this->provider(), 'm1@example.com');

        $this->assertSame($hash, $this->stored('m1'));
    }

    public function test_a_hash_changed_between_verify_and_rehash_stays(): void
    {
        $this->save('m1', self::md5Crypt(self::PASSWORD));
        $provider = $this->provider();
        $user = $provider->findByCredentials(['email' => 'm1@example.com']);
        $this->assertNotNull($user);
        $this->assertTrue($this->hasher->verify(self::PASSWORD, $user->getPasswordHash()));

        // Meanwhile the password is changed (another request).
        $newer = $this->hasher->hash('another password');
        $this->save('m1', $newer, epoch: '4');

        PasswordRehash::afterVerify($provider, $user, self::PASSWORD, $this->hasher);

        $this->assertSame($newer, $this->stored('m1'));
    }

    public function test_update_password_hash_compares_and_sets(): void
    {
        $this->save('m1', 'old-hash');
        $provider = $this->provider();
        $user = $provider->findById('m1');
        $this->assertInstanceOf(UpdatesPasswordHash::class, $provider);

        $this->assertFalse($provider->updatePasswordHash($user, 'not-the-stored-one', 'new-hash'));
        $this->assertSame('old-hash', $this->stored('m1'));
        $this->assertTrue($provider->updatePasswordHash($user, 'old-hash', 'new-hash'));
        $this->assertSame('new-hash', $this->stored('m1'));
        $this->assertSame('3', $this->stored('m1', 'auth_epoch'));
    }

    public function test_update_password_hash_refuses_other_users(): void
    {
        $this->save('m1', 'old-hash');
        $provider = $this->provider();

        $this->assertFalse($provider->updatePasswordHash(new ArrayUser('m1', 'm1@example.com', 'old-hash'), 'old-hash', 'new-hash'), 'not a record user');
        $other = new TypeDefinition('staff', 'member', 'sqlite', $this->member->fields);
        $this->assertFalse($provider->updatePasswordHash(new RecordUser(new DynamicRecord($other, ['uuid' => 'm1'])), 'old-hash', 'new-hash'), 'another type');
        $this->assertSame('old-hash', $this->stored('m1'));
    }

    public function test_a_config_user_signs_in_and_nothing_changes(): void
    {
        $old = self::md5Crypt(self::PASSWORD);
        $config = new ConfigUserProvider([['email' => 'cfg@example.com', 'password_hash' => $old, 'roles' => ['admin']]]);
        $chain = new ChainUserProvider([$this->provider(), $config]);

        $user = $this->signIn($chain, 'cfg@example.com');

        $this->assertSame($old, $user->getPasswordHash());
        $this->assertSame($old, $config->findByCredentials(['email' => 'cfg@example.com'])?->getPasswordHash());
        $this->assertNull($this->dm->findType('member', 'cfg@example.com'), 'no record made');
    }

    public function test_a_record_behind_a_chain_and_a_lazy_provider_is_upgraded(): void
    {
        $this->save('m1', self::md5Crypt(self::PASSWORD));
        $lazy = new LazyUserProvider('members', RecordUserProvider::class, fn (): object => $this->provider());
        $chain = new ChainUserProvider([new ConfigUserProvider([]), $lazy]);

        $this->signIn($chain, 'm1@example.com');

        $this->assertFalse($this->hasher->needsRehash($this->stored('m1')));
    }

    public function test_a_failure_is_logged_without_the_password_and_the_account_is_unchanged(): void
    {
        $old = self::md5Crypt(self::PASSWORD);
        $this->save('m1', $old);
        $user = $this->provider()->findById('m1');
        $failing = new class implements UserProviderInterface, UpdatesPasswordHash {
            public function findById(string $id): ?Authenticatable { return null; }
            public function findByCredentials(array $credentials): ?Authenticatable { return null; }
            public function updatePasswordHash(Authenticatable $user, string $oldHash, string $newHash): bool
            {
                throw new \RuntimeException('storage is down');
            }
        };
        $log = $this->dir . '/error.log';
        $previous = ini_set('error_log', $log);
        try {
            PasswordRehash::afterVerify($failing, $user, self::PASSWORD, $this->hasher);
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $logged = (string) @file_get_contents($log);
        @unlink($log);
        $this->assertStringContainsString('m1', $logged);
        $this->assertStringNotContainsString(self::PASSWORD, $logged);
        $this->assertSame($old, $this->stored('m1'));
    }

    public function test_a_provider_without_updates_is_left_alone(): void
    {
        $old = self::md5Crypt(self::PASSWORD);
        $config = new ConfigUserProvider([['email' => 'cfg@example.com', 'password_hash' => $old, 'roles' => []]]);

        $user = $this->signIn($config, 'cfg@example.com');

        $this->assertSame($old, $user->getPasswordHash());
    }

    public function test_the_provider_takes_its_type_from_the_resolver(): void
    {
        $this->expectException(UserProviderUnavailable::class);
        $this->expectExceptionMessage('does not exist');

        $this->provider(static fn (string $type): ?TypeDefinition => null);
    }

    public function test_without_a_resolver_the_provider_uses_the_data_managers_definition(): void
    {
        $this->save('m1', 'h');

        $provider = new RecordUserProvider($this->dm, ['type' => 'member']);

        $this->assertSame('m1', $provider->findById('m1')?->getAuthId());
        $this->expectException(UserProviderUnavailable::class);
        new RecordUserProvider($this->dm, ['type' => 'nope']);
    }
}
