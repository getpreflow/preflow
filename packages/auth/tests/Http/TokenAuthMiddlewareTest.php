<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\GuardInterface;
use Preflow\Auth\Http\TokenAuthMiddleware;
use Preflow\Auth\PersonalAccessToken;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\TokenGuard;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Exceptions\UnauthorizedHttpException;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\ModelMetadata;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class TokenAuthMiddlewareTest extends TestCase
{
    private TestUser $user;
    private AuthManager $manager;
    private string $plain;
    private ?string $dir = null;

    protected function setUp(): void
    {
        ModelMetadata::clearCache();
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE user_tokens (uuid TEXT PRIMARY KEY, tokenHash TEXT, userId TEXT, name TEXT, createdAt TEXT)');
        $dm = new DataManager(['default' => new SqliteDriver($pdo)]);

        $this->user = new TestUser(uuid: 'user-1', email: 'test@example.com');
        $user = $this->user;
        $provider = new class($user) implements UserProviderInterface {
            public function __construct(private readonly TestUser $user) {}
            public function findById(string $id): ?Authenticatable { return $id === $this->user->uuid ? $this->user : null; }
            public function findByCredentials(array $credentials): ?Authenticatable { return null; }
        };

        $this->plain = PersonalAccessToken::generatePlainToken();
        $token = new PersonalAccessToken();
        $token->uuid = 'token-1';
        $token->tokenHash = PersonalAccessToken::hashToken($this->plain);
        $token->userId = $user->uuid;
        $dm->save($token);

        // A session guard with a signed-in user, to prove a cookie never passes a token route.
        $session = new class($user) implements GuardInterface {
            public function __construct(private readonly TestUser $user) {}
            public function user(ServerRequestInterface $request): ?Authenticatable { return $this->user; }
            public function validate(array $credentials): bool { return false; }
            public function login(Authenticatable $user, ServerRequestInterface $request): void {}
            public function logout(ServerRequestInterface $request): void {}
        };
        $this->manager = new AuthManager(['web' => $session, 'api' => new TokenGuard($provider, $dm)], 'web');
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    private function request(?string $authorization = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', 'http://localhost/api/things');

        return $authorization === null ? $request : $request->withHeader('Authorization', $authorization);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $seen = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new Response(200);
            }
        };
    }

    public function test_valid_bearer_token_passes_and_handler_sees_the_user(): void
    {
        $handler = $this->handler();
        $response = (new TokenAuthMiddleware($this->manager))->process($this->request('Bearer ' . $this->plain), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($this->user, $handler->seen->getAttribute(Authenticatable::class));
        $this->assertSame($this->user, $this->manager->user());
        $this->assertSame('api', $this->manager->guardName());
    }

    /** @return array<string, array{0: ?string}> */
    public static function rejectedHeaders(): array
    {
        return [
            'no header' => [null],
            'unknown token' => ['Bearer ' . str_repeat('a', 64)],
            'empty bearer' => ['Bearer '],
            'other scheme' => ['Basic abc'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedHeaders')]
    public function test_missing_or_bad_token_is_401_with_www_authenticate(?string $header): void
    {
        try {
            (new TokenAuthMiddleware($this->manager))->process($this->request($header), $this->handler());
            $this->fail('Expected a 401.');
        } catch (UnauthorizedHttpException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertSame(['WWW-Authenticate' => 'Bearer'], $e->headers);
        }
    }

    public function test_a_signed_in_session_user_without_a_token_is_401(): void
    {
        // Another guard resolved the user earlier in the request (ResolveUserMiddleware does).
        $this->manager->setUser($this->user, 'web');

        $this->expectException(UnauthorizedHttpException::class);
        (new TokenAuthMiddleware($this->manager))->process($this->request(), $this->handler());
    }

    public function test_without_a_token_guard_it_is_401(): void
    {
        $manager = new AuthManager([], 'web');

        $this->expectException(UnauthorizedHttpException::class);
        (new TokenAuthMiddleware($manager))->process($this->request('Bearer ' . $this->plain), $this->handler());
    }

    public function test_named_session_guard_never_passes_a_token_route(): void
    {
        // auth.api_guard pointing at the session guard: its signed-in user must not get through.
        $this->assertNull($this->manager->tokenGuardName('web'));
        $this->expectException(UnauthorizedHttpException::class);
        (new TokenAuthMiddleware($this->manager, 'web'))->process($this->request(), $this->handler());
    }

    public function test_named_second_token_guard_is_the_one_used(): void
    {
        $provider = new class implements UserProviderInterface {
            public function findById(string $id): ?Authenticatable { return new TestUser(uuid: $id); }
            public function findByCredentials(array $credentials): ?Authenticatable { return null; }
        };
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE user_tokens (uuid TEXT PRIMARY KEY, tokenHash TEXT, userId TEXT, name TEXT, createdAt TEXT)');
        $dm = new DataManager(['default' => new SqliteDriver($pdo)]);
        $token = new PersonalAccessToken();
        $token->uuid = 't2';
        $token->tokenHash = PersonalAccessToken::hashToken('plain-two');
        $token->userId = 'second-user';
        $dm->save($token);
        // The first token guard finds nothing (its own empty table); the second knows the token.
        $emptyDm = new DataManager(['default' => new SqliteDriver((function () { $p = new \PDO('sqlite::memory:'); $p->exec('CREATE TABLE user_tokens (uuid TEXT PRIMARY KEY, tokenHash TEXT, userId TEXT, name TEXT, createdAt TEXT)'); return $p; })())]);
        $manager = new AuthManager(['one' => new TokenGuard($provider, $emptyDm), 'two' => new TokenGuard($provider, $dm)], 'one');

        $this->assertSame('one', $manager->tokenGuardName());
        $this->assertSame('two', $manager->tokenGuardName('two'));
        $handler = $this->handler();
        (new TokenAuthMiddleware($manager, 'two'))->process($this->request('Bearer plain-two'), $handler);
        $this->assertSame('second-user', $handler->seen->getAttribute(Authenticatable::class)->getAuthId());
        $this->assertSame('two', $manager->guardName());

        $this->expectException(UnauthorizedHttpException::class);
        (new TokenAuthMiddleware($manager))->process($this->request('Bearer plain-two'), $this->handler());
    }

    public function test_named_guard_that_is_not_configured_is_401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);
        (new TokenAuthMiddleware($this->manager, 'ghost'))->process($this->request('Bearer ' . $this->plain), $this->handler());
    }

    #[RunInSeparateProcess]
    public function test_route_with_the_middleware_answers_401_json_on_an_api_path(): void
    {
        $this->dir = sys_get_temp_dir() . '/token_mw_' . bin2hex(random_bytes(4));
        foreach (['config', 'app/Controllers', 'storage'] as $d) {
            mkdir($this->dir . '/' . $d, 0777, true);
        }
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'token-mw-test-key-0123456789abcdef','url'=>'https://example.com','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default'=>'sqlite','drivers'=>['sqlite'=>['path'=>'{$this->dir}/storage/app.sqlite']]];\n");
        file_put_contents($this->dir . '/config/auth.php', '<?php return ' . var_export([
            'default_guard' => 'web',
            'guards' => [
                'web' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users'],
                'api' => ['class' => TokenGuard::class, 'provider' => 'users'],
            ],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [['email' => 'a@b.c', 'password_hash' => '', 'roles' => []]]]],
            'session' => ['cookie' => 'token_mw', 'secure' => false],
        ], true) . ';');
        file_put_contents($this->dir . '/app/Controllers/SecretController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Preflow\Auth\Http\TokenAuthMiddleware;
            use Preflow\Routing\Attributes\Get;
            use Preflow\Routing\Attributes\Middleware;
            use Preflow\Routing\Attributes\Route;

            #[Route('/api')]
            final class SecretController
            {
                #[Get('/secret')]
                #[Middleware(TokenAuthMiddleware::class)]
                public function secret(): array
                {
                    return ['data' => 'ok'];
                }
            }
            PHP);
        $dir = $this->dir;
        spl_autoload_register(static function (string $class) use ($dir): void {
            if ($class === 'App\\Controllers\\SecretController') {
                require $dir . '/app/Controllers/SecretController.php';
            }
        });
        $pdo = new \PDO('sqlite:' . $dir . '/storage/app.sqlite');
        $pdo->exec('CREATE TABLE user_tokens (uuid TEXT PRIMARY KEY, tokenHash TEXT, userId TEXT, name TEXT, createdAt TEXT)');
        $id = (string) (new \Preflow\Auth\ConfigUserProvider([['email' => 'a@b.c', 'password_hash' => '']]))->findByCredentials(['email' => 'a@b.c'])?->getAuthId();
        $plain = PersonalAccessToken::generatePlainToken();
        $pdo->prepare('INSERT INTO user_tokens VALUES (?, ?, ?, ?, ?)')->execute(['t1', PersonalAccessToken::hashToken($plain), $id, 'x', null]);

        $app = Application::create($dir);
        $app->setRunningInConsole(false);
        $app->boot();

        $denied = $app->handle(new ServerRequest('GET', 'https://example.com/api/secret'));
        $this->assertSame(401, $denied->getStatusCode());
        $this->assertSame('application/json', $denied->getHeaderLine('Content-Type'));
        $this->assertSame('Bearer', $denied->getHeaderLine('WWW-Authenticate'));
        $this->assertSame('Unauthorized', json_decode((string) $denied->getBody(), true)['message']);

        $allowed = $app->handle(new ServerRequest('GET', 'https://example.com/api/secret', ['Authorization' => 'Bearer ' . $plain]));
        $this->assertSame(200, $allowed->getStatusCode(), (string) $allowed->getBody());
        $this->assertSame(['data' => 'ok'], json_decode((string) $allowed->getBody(), true));
    }
}
