<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\HasSessionEpoch;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Testing\ArraySession;
use Psr\Http\Message\ServerRequestInterface;

final class SessionGuardTest extends TestCase
{
    private TestUser $user;
    private UserProviderInterface $provider;
    private ArraySession $session;
    private NativePasswordHasher $hasher;
    private SessionGuard $guard;

    protected function setUp(): void
    {
        $this->hasher = new NativePasswordHasher();
        $this->user = new TestUser(
            uuid: 'user-1',
            email: 'test@example.com',
            passwordHash: $this->hasher->hash('secret'),
        );

        $this->provider = new class($this->user) implements UserProviderInterface {
            public function __construct(private readonly TestUser $user) {}

            public function findById(string $id): ?Authenticatable
            {
                return $id === $this->user->uuid ? $this->user : null;
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return ($credentials['email'] ?? null) === $this->user->email ? $this->user : null;
            }
        };

        $this->session = new ArraySession();
        $this->guard = new SessionGuard($this->provider, $this->hasher);
    }

    private function createRequest(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/')
            ->withAttribute(SessionInterface::class, $this->session);
    }

    public function test_user_returns_null_when_not_logged_in(): void
    {
        $request = $this->createRequest();

        $this->assertNull($this->guard->user($request));
    }

    public function test_login_stores_user_id_in_session(): void
    {
        $request = $this->createRequest();
        $this->guard->login($this->user, $request);

        $this->assertSame('user-1', $this->session->get('_auth_user_id'));
    }

    public function test_login_regenerates_session(): void
    {
        $request = $this->createRequest();
        $idBefore = $this->session->getId();

        $this->guard->login($this->user, $request);

        $this->assertNotSame($idBefore, $this->session->getId());
    }

    public function test_user_returns_user_after_login(): void
    {
        $request = $this->createRequest();
        $this->guard->login($this->user, $request);

        $resolved = $this->guard->user($request);

        $this->assertNotNull($resolved);
        $this->assertSame('user-1', $resolved->getAuthId());
    }

    public function test_logout_invalidates_session(): void
    {
        $request = $this->createRequest();
        $this->guard->login($this->user, $request);

        $this->guard->logout($request);

        $this->assertNull($this->session->get('_auth_user_id'));
    }

    public function test_validate_with_correct_credentials(): void
    {
        $result = $this->guard->validate([
            'email' => 'test@example.com',
            'password' => 'secret',
        ]);

        $this->assertTrue($result);
    }

    public function test_validate_with_wrong_password(): void
    {
        $result = $this->guard->validate([
            'email' => 'test@example.com',
            'password' => 'wrongpass',
        ]);

        $this->assertFalse($result);
    }

    public function test_validate_with_unknown_email(): void
    {
        $result = $this->guard->validate([
            'email' => 'nobody@example.com',
            'password' => 'secret',
        ]);

        $this->assertFalse($result);
    }

    public function test_two_guards_keep_separate_users(): void
    {
        $members = new SessionGuard($this->provider, $this->hasher, '_auth_user_id.members', ['_auth_user_id']);
        $members->login($this->user, $this->createRequest());

        $this->assertSame('user-1', $this->session->get('_auth_user_id.members'));
        $this->assertNull($this->guard->user($this->createRequest())); // the default guard's key is untouched
        $this->assertSame($this->user, $members->user($this->createRequest()));
    }

    public function test_logout_of_one_guard_keeps_the_other(): void
    {
        $staff = new SessionGuard($this->provider, $this->hasher, '_auth_user_id', ['_auth_user_id.members']);
        $members = new SessionGuard($this->provider, $this->hasher, '_auth_user_id.members', ['_auth_user_id']);
        $staff->login($this->user, $this->createRequest());
        $members->login($this->user, $this->createRequest());
        $id = $this->session->getId();

        $members->logout($this->createRequest());

        $this->assertNull($members->user($this->createRequest()));
        $this->assertSame($this->user, $staff->user($this->createRequest()));
        $this->assertNotSame($id, $this->session->getId());
    }

    public function test_logout_of_the_last_guard_invalidates_the_session(): void
    {
        $this->guard->login($this->user, $this->createRequest());
        $this->session->set('cart', 'x');

        $this->guard->logout($this->createRequest());

        $this->assertNull($this->session->get('cart'));
    }

    /** A user whose epoch the test moves on, as a password change does. */
    private function epochUser(string $epoch): Authenticatable&HasSessionEpoch
    {
        return new class($epoch) implements Authenticatable, HasSessionEpoch {
            public function __construct(public string $epoch) {}
            public function getAuthId(): string { return 'user-e'; }
            public function getPasswordHash(): string { return ''; }
            public function getRoles(): array { return []; }
            public function hasRole(string $role): bool { return false; }
            public function getSessionEpoch(): string { return $this->epoch; }
        };
    }

    private function epochGuard(Authenticatable $user): SessionGuard
    {
        $provider = new class($user) implements UserProviderInterface {
            public function __construct(private readonly Authenticatable $user) {}
            public function findById(string $id): ?Authenticatable { return $id === $this->user->getAuthId() ? $this->user : null; }
            public function findByCredentials(array $credentials): ?Authenticatable { return null; }
        };

        return new SessionGuard($provider, $this->hasher, '_auth_user_id.members', name: 'members');
    }

    public function test_login_keeps_the_users_session_epoch(): void
    {
        $user = $this->epochUser('3');
        $guard = $this->epochGuard($user);

        $guard->login($user, $this->createRequest());

        $this->assertSame('3', $this->session->get('_auth_user_id.members:epoch'));
        $this->assertSame($user, $guard->user($this->createRequest()));
    }

    public function test_a_session_of_an_older_epoch_is_signed_out(): void
    {
        $user = $this->epochUser('0');
        $guard = $this->epochGuard($user);
        $guard->login($user, $this->createRequest());

        $user->epoch = '1'; // the password changed in another session

        $this->assertNull($guard->user($this->createRequest()));
        $this->assertNull($this->session->get('_auth_user_id.members'), 'this guard\'s sign-in is gone');
        $this->assertNull($this->session->get('_auth_user_id.members:epoch'));
    }

    public function test_signing_in_again_takes_the_new_epoch(): void
    {
        $user = $this->epochUser('0');
        $guard = $this->epochGuard($user);
        $guard->login($user, $this->createRequest());
        $user->epoch = '1';

        $guard->login($user, $this->createRequest());

        $this->assertSame($user, $guard->user($this->createRequest()));
    }

    public function test_a_session_from_before_epochs_counts_as_epoch_0(): void
    {
        $user = $this->epochUser('0');
        $guard = $this->epochGuard($user);
        $this->session->set('_auth_user_id.members', 'user-e'); // signed in before the epoch was stored

        $this->assertSame($user, $guard->user($this->createRequest()));
        $user->epoch = '1';
        $this->assertNull($guard->user($this->createRequest()));
    }

    public function test_logout_drops_the_epoch(): void
    {
        $user = $this->epochUser('2');
        $guard = $this->epochGuard($user);
        $guard->login($user, $this->createRequest());

        $guard->logout($this->createRequest());

        $this->assertNull($this->session->get('_auth_user_id.members:epoch'));
    }
}
