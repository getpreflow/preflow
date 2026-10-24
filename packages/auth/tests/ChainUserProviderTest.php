<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\ChainUserProvider;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\ClaimsCredentials;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\LazyUserProvider;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\UserProviderInterface;
use Preflow\Auth\UserProviderUnavailable;

final class ChainUserProviderTest extends TestCase
{
    private function chain(): ChainUserProvider
    {
        return new ChainUserProvider([
            new ConfigUserProvider([['email' => 'both@x.test', 'password_hash' => 'first', 'roles' => ['a']], ['email' => 'one@x.test', 'password_hash' => 'h']]),
            new ConfigUserProvider([['email' => 'both@x.test', 'password_hash' => 'second'], ['email' => 'two@x.test', 'password_hash' => 'h']]),
        ]);
    }

    public function test_the_first_provider_with_a_hit_wins(): void
    {
        $this->assertSame('first', $this->chain()->findById('both@x.test')?->getPasswordHash());
        $this->assertSame('first', $this->chain()->findByCredentials(['email' => 'both@x.test'])?->getPasswordHash());
    }

    public function test_an_id_known_to_one_provider_only_resolves_from_it(): void
    {
        $this->assertSame('two@x.test', $this->chain()->findById('two@x.test')?->getAuthId());
        $this->assertSame('one@x.test', $this->chain()->findByCredentials(['email' => 'one@x.test'])?->getAuthId());
        $this->assertSame('two@x.test', $this->chain()->findByCredentials(['email' => 'two@x.test'])?->getAuthId());
    }

    public function test_no_hit_and_an_empty_chain_resolve_nobody(): void
    {
        $this->assertNull($this->chain()->findById('nobody'));
        $this->assertNull($this->chain()->findByCredentials(['email' => 'nobody']));
        $this->assertNull((new ChainUserProvider([]))->findById('x'));
    }

    /** A record-like provider: knows $email, resolves its user only when $active. */
    private function records(string $email, string $hash, bool $active): UserProviderInterface
    {
        return new class($email, $hash, $active) implements UserProviderInterface, ClaimsCredentials {
            public function __construct(private string $email, private string $hash, private bool $active) {}

            public function claims(array $credentials): bool
            {
                return ($credentials['email'] ?? null) === $this->email;
            }

            public function findById(string $id): ?Authenticatable
            {
                return null;
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return $this->claims($credentials) && $this->active ? new TestUser('r1', $this->email, $this->hash) : null;
            }
        };
    }

    private function configUser(string $email, string $hash): ConfigUserProvider
    {
        return new ConfigUserProvider([['email' => $email, 'password_hash' => $hash]]);
    }

    public function test_an_inactive_record_blocks_the_config_user_with_the_same_email(): void
    {
        $hasher = new NativePasswordHasher();
        $chain = new ChainUserProvider([
            $this->records('a@x.test', $hasher->hash('record-pw'), false),
            $this->configUser('a@x.test', $hasher->hash('config-pw')),
        ]);

        $this->assertNull($chain->findByCredentials(['email' => 'a@x.test']));
        $this->assertFalse((new SessionGuard($chain, $hasher))->validate(['email' => 'a@x.test', 'password' => 'config-pw']));
        $this->assertSame('b@x.test', (new ChainUserProvider([$this->records('a@x.test', 'h', false), $this->configUser('b@x.test', 'h')]))
            ->findByCredentials(['email' => 'b@x.test'])?->getAuthId(), 'other e-mails still fall through');
    }

    public function test_the_record_decides_for_its_email_so_the_config_password_does_not_work(): void
    {
        $hasher = new NativePasswordHasher();
        $guard = new SessionGuard(new ChainUserProvider([
            $this->records('a@x.test', $hasher->hash('record-pw'), true),
            $this->configUser('a@x.test', $hasher->hash('config-pw')),
        ]), $hasher);

        $this->assertFalse($guard->validate(['email' => 'a@x.test', 'password' => 'config-pw']));
        $this->assertTrue($guard->validate(['email' => 'a@x.test', 'password' => 'record-pw']));
    }

    public function test_an_unavailable_lazy_member_claims_nothing(): void
    {
        $lazy = new LazyUserProvider('staff', 'Missing', static fn (): object => throw new UserProviderUnavailable('gone'));
        $lazy->bootResolve();
        $chain = new ChainUserProvider([$lazy, $this->configUser('a@x.test', 'h')]);

        $this->assertSame('a@x.test', $chain->findByCredentials(['email' => 'a@x.test'])?->getAuthId());
    }

    public function test_a_lazy_member_passes_the_claim_through(): void
    {
        $lazy = new LazyUserProvider('staff', 'X', fn (): object => $this->records('a@x.test', 'h', false));
        $chain = new ChainUserProvider([$lazy, $this->configUser('a@x.test', 'h')]);

        $this->assertNull($chain->findByCredentials(['email' => 'a@x.test']));
    }

    public function test_the_chain_lists_its_members_in_order(): void
    {
        $a = $this->configUser('a@x.test', 'h');
        $b = $this->configUser('b@x.test', 'h');

        $this->assertSame([$a, $b], (new ChainUserProvider([$a, $b]))->providers());
    }

    public function test_a_lazy_provider_is_built_once_and_an_unavailable_one_is_none(): void
    {
        $built = 0;
        $lazy = new LazyUserProvider('staff', 'X', function () use (&$built): object {
            $built++;

            return $this->configUser('a@x.test', 'h');
        });
        $this->assertSame($lazy->inner(), $lazy->inner());
        $this->assertNull($lazy->bootResolve(), 'boot after a first use: nothing to report');
        $this->assertSame(1, $built);

        $gone = new UserProviderUnavailable('gone');
        $missing = new LazyUserProvider('staff', 'Missing', static fn (): object => throw $gone);
        $this->assertNull($missing->inner());
        $this->assertSame($gone, $missing->bootResolve(), 'the boot still reports the failure');
    }

    public function test_a_session_guard_tells_whether_it_remembers(): void
    {
        $provider = $this->configUser('a@x.test', 'h');
        $remember = new \Preflow\Auth\Remember\RememberMe(new \Preflow\Data\DataManager(['default' => new \Preflow\Data\Driver\SqliteDriver(new \PDO('sqlite::memory:'))], 'default'));

        $this->assertFalse((new SessionGuard($provider, new NativePasswordHasher()))->remembers());
        $this->assertFalse((new SessionGuard($provider, new NativePasswordHasher(), rememberMe: $remember))->remembers(), 'no cookie config');
        $this->assertTrue((new SessionGuard($provider, new NativePasswordHasher(), rememberMe: $remember, remember: ['cookie' => 'r']))->remembers());
    }
}
