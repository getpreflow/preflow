<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
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
}
