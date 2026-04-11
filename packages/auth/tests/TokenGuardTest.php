<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\PersonalAccessToken;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\TokenGuard;
use Preflow\Auth\UserProviderInterface;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\ModelMetadata;
use Psr\Http\Message\ServerRequestInterface;

final class TokenGuardTest extends TestCase
{
    private TestUser $user;
    private UserProviderInterface $provider;
    private DataManager $dm;
    private \PDO $pdo;
    private TokenGuard $guard;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE user_tokens (uuid TEXT PRIMARY KEY, tokenHash TEXT, userId TEXT, name TEXT, createdAt TEXT)');
        $this->dm = new DataManager(['default' => new SqliteDriver($this->pdo)]);

        $this->user = new TestUser(uuid: 'user-1', email: 'test@example.com');

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

        $this->guard = new TokenGuard($this->provider, $this->dm);
        $this->factory = new Psr17Factory();
    }

    private function createRequest(string $bearerToken = ''): ServerRequestInterface
    {
        $request = $this->factory->createServerRequest('GET', '/api/resource');

        if ($bearerToken !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . $bearerToken);
        }

        return $request;
    }

    private function storeToken(string $plainToken): void
    {
        $token = new PersonalAccessToken();
        $token->uuid = 'token-' . uniqid();
        $token->tokenHash = PersonalAccessToken::hashToken($plainToken);
        $token->userId = $this->user->uuid;
        $token->name = 'test-token';
        $this->dm->save($token);
    }

    public function test_user_with_valid_bearer_token(): void
    {
        $plainToken = PersonalAccessToken::generatePlainToken();
        $this->storeToken($plainToken);

        $request = $this->createRequest($plainToken);
        $resolved = $this->guard->user($request);

        $this->assertNotNull($resolved);
        $this->assertSame('user-1', $resolved->getAuthId());
    }

    public function test_user_returns_null_without_token(): void
    {
        $request = $this->createRequest();

        $this->assertNull($this->guard->user($request));
    }

    public function test_user_returns_null_with_invalid_token(): void
    {
        $plainToken = PersonalAccessToken::generatePlainToken();
        $this->storeToken($plainToken);

        $request = $this->createRequest('totallywrongtoken');

        $this->assertNull($this->guard->user($request));
    }

    public function test_login_and_logout_are_noops(): void
    {
        $request = $this->createRequest();

        // Neither method should throw
        $this->guard->login($this->user, $request);
        $this->guard->logout($request);

        $this->addToAssertionCount(1);
    }
}
