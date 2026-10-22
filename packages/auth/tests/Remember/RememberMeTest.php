<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Remember;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Http\ResolveUserMiddleware;
use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\SessionGuard;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Error\ErrorHandler;
use Preflow\Core\Error\ProdErrorRenderer;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Http\CookieQueue;
use Preflow\Core\Http\MiddlewarePipeline;
use Preflow\Core\Kernel;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Core\Http\Session\SessionMiddleware;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Testing\ArraySession;
use Preflow\Testing\DataTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RememberMeTest extends DataTestCase
{
    private const CONFIG = ['cookie' => 'ss_member', 'days' => 30, 'path' => '/', 'secure' => true];

    private int $now = 1_000_000;

    /** @var list<string> */
    private array $lockDirs = [];

    private UserProviderInterface $provider;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_23_000001_auth_remember.php';
        $migration->up(new Schema($this->getPdo()));

        $this->provider = new class implements UserProviderInterface {
            public function findById(string $id): ?Authenticatable
            {
                return in_array($id, ['user-1', 'user-2'], true) ? new TestUser(uuid: $id) : null;
            }

            public function findByCredentials(array $credentials): ?Authenticatable
            {
                return null;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->lockDirs as $dir) {
            array_map(unlink(...), glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    private function remember(string $storage = 'default', ?\Closure $clock = null): RememberMe
    {
        $lockDir = sys_get_temp_dir() . '/preflow_remember_locks_' . uniqid();
        $this->lockDirs[] = $lockDir;

        return new RememberMe($this->dataManager(), $storage, $clock ?? fn (): int => $this->now, $lockDir);
    }

    private function guard(?RememberMe $remember, array $config = self::CONFIG, ?CookieQueue $queue = null): SessionGuard
    {
        return new SessionGuard($this->provider, new NativePasswordHasher(), '_auth_user_id.member', [], $remember, 'member', $remember === null ? [] : $config, $queue ?? new CookieQueue());
    }

    /**
     * Runs $guard's ResolveUserMiddleware (behind a SessionMiddleware when $paths is given)
     * with $then as the controller.
     *
     * @param array<string, string> $cookies
     * @param list<string>|null     $paths
     * @return array{ResponseInterface, AuthManager, ?ServerRequestInterface}
     */
    private function handle(SessionGuard $guard, array $cookies = [], ?\Closure $then = null, ?ArraySession $session = null, string $path = '/members', ?array $paths = null): array
    {
        $authManager = new AuthManager(['member' => $guard], 'member');
        $request = (new Psr17Factory())->createServerRequest('GET', $path)->withCookieParams($cookies);
        $session ??= new ArraySession();
        $handler = new class($then) implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;

            public function __construct(private readonly ?\Closure $then) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                if ($this->then !== null) {
                    ($this->then)($request);
                }

                return new Response(200);
            }
        };
        $resolve = new ResolveUserMiddleware($authManager, ['member' => $guard]);
        if ($paths === null) {
            $session->start();
            $response = $resolve->process($request->withAttribute(SessionInterface::class, $session), $handler);
        } else {
            $outer = new class($resolve, $handler) implements RequestHandlerInterface {
                public function __construct(private readonly MiddlewareInterface $mw, private readonly RequestHandlerInterface $next) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->mw->process($request, $this->next);
                }
            };
            $response = (new SessionMiddleware($session, $paths))->process($request, $outer);
        }

        return [$response, $authManager, $handler->captured];
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->dataManager()->raw('SELECT * FROM auth_remember ORDER BY user_id');
    }

    private static function cookieValue(ResponseInterface $response): string
    {
        $header = $response->getHeaderLine('Set-Cookie');
        preg_match('/^ss_member=([^;]*)/', $header, $m);

        return $m[1] ?? throw new \RuntimeException("no ss_member cookie in [{$header}]");
    }

    public function test_login_with_remember_sets_cookie_with_attributes(): void
    {
        $guard = $this->guard($this->remember());
        [$response] = $this->handle($guard, then: fn (ServerRequestInterface $r) => $guard->login(new TestUser(uuid: 'user-1'), $r, true));

        $cookies = $response->getHeader('Set-Cookie');
        $this->assertCount(1, $cookies);
        $this->assertMatchesRegularExpression(
            '/^ss_member=[A-Za-z0-9_-]{22}:[A-Za-z0-9_-]{43}; Max-Age=2592000; Path=\/; HttpOnly; SameSite=Lax; Secure$/',
            $cookies[0],
        );

        [$selector, $validator] = explode(':', self::cookieValue($response));
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(['id', 'guard', 'user_id', 'validator_hash', 'previous_hash', 'rotated_at', 'expires_at'], array_keys($rows[0]));
        $this->assertSame([$selector, 'member', 'user-1', hash('sha256', $validator), $this->now + 30 * 86400], [
            $rows[0]['id'], $rows[0]['guard'], $rows[0]['user_id'], $rows[0]['validator_hash'], (int) $rows[0]['expires_at'],
        ]);
        $this->assertStringNotContainsString($validator, json_encode($rows, JSON_THROW_ON_ERROR));

        // Without Secure when not configured; login without remember sets nothing.
        $plain = $this->guard($this->remember(), ['secure' => false] + self::CONFIG);
        [$response] = $this->handle($plain, then: fn (ServerRequestInterface $r) => $plain->login(new TestUser(uuid: 'user-1'), $r, true));
        $this->assertStringEndsWith('; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        [$response] = $this->handle($plain, then: fn (ServerRequestInterface $r) => $plain->login(new TestUser(uuid: 'user-1'), $r));
        $this->assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function test_cookie_restores_user_and_rotates(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $cookie = $remember->issue('member', 'user-1');
            [$selector] = explode(':', $cookie);
            $session = new ArraySession();

            [$response, $authManager, $captured] = $this->handle($this->guard($remember), ['ss_member' => $cookie], session: $session);

            $this->assertSame('user-1', $authManager->user()?->getAuthId(), $storage);
            $this->assertSame('member', $authManager->guardName(), $storage);
            $this->assertSame('user-1', $captured?->getAttribute(Authenticatable::class)?->getAuthId(), $storage);
            $this->assertSame('user-1', $session->get('_auth_user_id.member'), $storage);

            $rotated = self::cookieValue($response);
            $this->assertStringStartsWith($selector . ':', $rotated, $storage);
            $this->assertNotSame($cookie, $rotated, $storage);
            $this->assertStringContainsString('; Max-Age=2592000; Path=/; HttpOnly; SameSite=Lax; Secure', $response->getHeaderLine('Set-Cookie'), $storage);

            // The rotated cookie works on the next visit (new session), once more rotated.
            $next = $remember->resolve('member', $rotated);
            $this->assertSame('user-1', $next['userId'] ?? null, $storage);
            $this->assertStringStartsWith($selector . ':', $next['cookie'] ?? '', $storage);

            // An already signed-in session is left alone: no restore, no cookie.
            [$response] = $this->handle($this->guard($remember), ['ss_member' => $next['cookie']], session: $session);
            $this->assertSame([], $response->getHeader('Set-Cookie'), $storage);
            $this->assertSame('user-1', $remember->resolve('member', $next['cookie'])['userId'] ?? null, $storage);

            // Another guard's name does not match the row.
            $this->assertNull($remember->resolve('staff', $remember->issue('member', 'user-2')), $storage);
        }
    }

    public function test_replayed_old_validator_revokes_all(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $stolen = $remember->issue('member', 'user-1');
            $otherDevice = $remember->issue('member', 'user-1');
            $otherGuard = $remember->issue('staff', 'user-1');
            $otherUser = $remember->issue('member', 'user-2');

            // The owner comes back first: the cookie rotates.
            $owner = $remember->resolve('member', $stolen);
            $this->assertNotNull($owner, $storage);
            $this->now += RememberMe::GRACE + 1;

            // The thief replays the old value: nobody, a clearing cookie, and every
            // member token of user-1 is gone (the rotated one and the other device).
            [$response, $authManager] = $this->handle($this->guard($remember), ['ss_member' => $stolen]);
            $this->assertNull($authManager->user(), $storage);
            $this->assertSame('ss_member=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax; Secure', $response->getHeaderLine('Set-Cookie'), $storage);
            $this->assertNull($remember->resolve('member', $owner['cookie']), $storage);
            $this->assertNull($remember->resolve('member', $otherDevice), $storage);

            $this->assertSame('user-1', $remember->resolve('staff', $otherGuard)['userId'] ?? null, $storage);
            $this->assertSame('user-2', $remember->resolve('member', $otherUser)['userId'] ?? null, $storage);
            $this->now -= RememberMe::GRACE + 1;
        }
    }

    public function test_expired_remember_ignored(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $cookie = $remember->issue('member', 'user-1');
            $live = $remember->issue('member', 'user-2', 31 * 86400);
            $this->now += 30 * 86400;

            [$response, $authManager] = $this->handle($this->guard($remember), ['ss_member' => $cookie]);
            $this->assertNull($authManager->user(), $storage);
            $this->assertStringStartsWith('ss_member=; Max-Age=0;', $response->getHeaderLine('Set-Cookie'), $storage);
            $this->assertNull($remember->resolve('member', $cookie), $storage);
            $this->assertSame('user-2', $remember->resolve('member', $live)['userId'] ?? null, $storage);
            $this->now -= 30 * 86400;
        }

        // Malformed values are nobody, without touching storage.
        $remember = $this->remember();
        foreach (['', 'x', 'a:b', '../../etc:passwd', str_repeat('a', 22) . ':' . str_repeat('b', 42)] as $bad) {
            $this->assertNull($remember->resolve('member', $bad), $bad);
        }
    }

    public function test_logout_clears_cookie_and_row(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $cookie = $remember->issue('member', 'user-1');
            $kept = $remember->issue('member', 'user-1');
            $guard = $this->guard($remember);
            $session = new ArraySession();
            $session->set('_auth_user_id.member', 'user-1');

            [$response, , $captured] = $this->handle($guard, ['ss_member' => $cookie], fn (ServerRequestInterface $r) => $guard->logout($r), $session);

            $this->assertSame('ss_member=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax; Secure', $response->getHeaderLine('Set-Cookie'), $storage);
            $this->assertNull($session->get('_auth_user_id.member'), $storage);
            $this->assertNull($remember->resolve('member', $cookie), $storage);
            // Only this device's token: another device stays remembered.
            $this->assertSame('user-1', $remember->resolve('member', $kept)['userId'] ?? null, $storage);
            $this->assertNotNull($captured, $storage);
        }
    }

    public function test_guard_without_remember_config_unchanged(): void
    {
        $remember = $this->remember();
        $cookie = $remember->issue('member', 'user-1');
        $guard = $this->guard(null);

        [$response, $authManager] = $this->handle($guard, ['ss_member' => $cookie], fn (ServerRequestInterface $r) => $guard->login(new TestUser(uuid: 'user-2'), $r, true));
        $this->assertSame([], $response->getHeader('Set-Cookie'));
        $this->assertNull($authManager->user());
        $this->assertCount(1, $this->rows());

        $session = new ArraySession();
        $session->set('_auth_user_id.member', 'user-1');
        [$response] = $this->handle($guard, ['ss_member' => $cookie], fn (ServerRequestInterface $r) => $guard->logout($r), $session);
        $this->assertSame([], $response->getHeader('Set-Cookie'));
        $this->assertCount(1, $this->rows());
        $this->assertSame([], $guard->pullCookies());
    }

    public function test_no_cookie_or_restore_outside_session_paths(): void
    {
        $remember = $this->remember();
        $cookie = $remember->issue('member', 'user-1');
        $guard = $this->guard($remember);
        $session = new ArraySession();

        [$response, $authManager, $captured] = $this->handle($guard, ['ss_member' => $cookie], session: $session, path: '/hello', paths: ['/members']);

        $this->assertFalse($session->isStarted());
        $this->assertNull($captured?->getAttribute(SessionInterface::class));
        $this->assertNull($authManager->user());
        $this->assertSame([], $response->getHeader('Set-Cookie'));
        // Not rotated: the cookie still works where the session lives.
        [$response, $authManager] = $this->handle($guard, ['ss_member' => $cookie], session: $session, path: '/members/home', paths: ['/members']);
        $this->assertTrue($session->isStarted());
        $this->assertSame('user-1', $authManager->user()?->getAuthId());
        $this->assertStringStartsWith('ss_member=', $response->getHeaderLine('Set-Cookie'));
    }

    public function test_rotation_is_decided_by_the_update(): void
    {
        // A second request rotates the same cookie between this one's read and its update.
        $hook = null;
        $remember = $this->remember('sqlite', function () use (&$hook): int {
            if ($hook !== null) {
                [$run, $hook] = [$hook, null];
                $run();
            }

            return $this->now;
        });
        $other = $this->remember('sqlite');
        $cookie = $other->issue('member', 'user-1');
        $won = null;
        $hook = function () use ($other, $cookie, &$won): void {
            $won = $other->resolve('member', $cookie);
        };

        // The loser still gets the user, without a cookie to send (the winner's response carries it).
        $this->assertSame(['userId' => 'user-1', 'cookie' => null], $remember->resolve('member', $cookie));
        $this->assertNotNull($won['cookie'] ?? null);
        // The loser revoked nothing: the winner's rotated cookie still works.
        $this->assertSame('user-1', $other->resolve('member', $won['cookie'])['userId'] ?? null);
    }

    public function test_previous_validator_accepted_within_grace(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $old = $remember->issue('member', 'user-1');
            $rotated = $remember->resolve('member', $old)['cookie'] ?? '';
            $this->now += RememberMe::GRACE;

            $this->assertSame(['userId' => 'user-1', 'cookie' => null], $remember->resolve('member', $old), $storage);
            // Through the middleware: signed in, and no cookie set or cleared.
            $session = new ArraySession();
            [$response, $authManager] = $this->handle($this->guard($remember), ['ss_member' => $old], session: $session);
            $this->assertSame('user-1', $authManager->user()?->getAuthId(), $storage);
            $this->assertSame('user-1', $session->get('_auth_user_id.member'), $storage);
            $this->assertSame([], $response->getHeader('Set-Cookie'), $storage);
            // Not rotated by the grace: the current value still works.
            $this->assertSame('user-1', $remember->resolve('member', $rotated)['userId'] ?? null, $storage);
            $this->now -= RememberMe::GRACE;
        }
    }

    public function test_previous_validator_after_grace_is_theft(): void
    {
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $old = $remember->issue('member', 'user-1');
            $otherDevice = $remember->issue('member', 'user-1');
            $rotated = $remember->resolve('member', $old)['cookie'] ?? '';
            $this->now += RememberMe::GRACE + 1;

            [$response, $authManager] = $this->handle($this->guard($remember), ['ss_member' => $old]);
            $this->assertNull($authManager->user(), $storage);
            $this->assertStringStartsWith('ss_member=; Max-Age=0;', $response->getHeaderLine('Set-Cookie'), $storage);
            $this->assertNull($remember->resolve('member', $rotated), $storage);
            $this->assertNull($remember->resolve('member', $otherDevice), $storage);
            $this->now -= RememberMe::GRACE + 1;
        }
    }

    public function test_concurrent_restore_read_after_commit_keeps_member_logged_in(): void
    {
        // Two tabs (or htmx loads) without a session send the same cookie; the second
        // reads the row only after the first rotated it.
        foreach (['sqlite', 'json'] as $storage) {
            $remember = $this->remember($storage);
            $cookie = $remember->issue('member', 'user-1');

            [$first, $firstAuth] = $this->handle($this->guard($remember), ['ss_member' => $cookie]);
            [$second, $secondAuth] = $this->handle($this->guard($remember), ['ss_member' => $cookie]);

            $this->assertSame('user-1', $firstAuth->user()?->getAuthId(), $storage);
            $this->assertSame('user-1', $secondAuth->user()?->getAuthId(), $storage);
            $this->assertCount(1, $first->getHeader('Set-Cookie'), $storage);
            $this->assertSame([], $second->getHeader('Set-Cookie'), $storage);
            $this->assertSame('user-1', $remember->resolve('member', self::cookieValue($first))['userId'] ?? null, $storage);
        }
    }

    public function test_rotated_cookie_reaches_error_responses(): void
    {
        $remember = $this->remember();
        $cookie = $remember->issue('member', 'user-1');
        $queue = new CookieQueue();
        $guard = $this->guard($remember, queue: $queue);
        $authManager = new AuthManager(['member' => $guard], 'member');
        $session = new ArraySession();
        $session->start();

        $pipeline = new MiddlewarePipeline();
        $pipeline->pipe(new class($session) implements MiddlewareInterface {
            public function __construct(private readonly SessionInterface $session) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute(SessionInterface::class, $this->session));
            }
        });
        $pipeline->pipe(new ResolveUserMiddleware($authManager, ['member' => $guard]));
        $router = new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                throw new NotFoundHttpException('gone');
            }
        };
        $fail = fn (): ResponseInterface => throw new \LogicException('not dispatched');
        $kernel = new Kernel(new Container(), $router, $pipeline, new ErrorHandler(new ProdErrorRenderer()), $fail, $fail, cookies: $queue);

        $response = $kernel->handle((new Psr17Factory())->createServerRequest('GET', '/members/stale')->withCookieParams(['ss_member' => $cookie]));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('user-1', $session->get('_auth_user_id.member'));
        $rotated = self::cookieValue($response);
        $this->assertNotSame($cookie, $rotated);
        $this->assertSame([], $queue->pull());
        // The browser got the new value: it works (and rotates) once the grace is over.
        $this->now += RememberMe::GRACE + 1;
        $this->assertNotNull($remember->resolve('member', $rotated)['cookie'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_boot_gives_the_guard_its_remember_config(): void
    {
        putenv('APP_SECURE=1');
        $dir = sys_get_temp_dir() . '/remember_boot_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        mkdir($dir . '/storage', 0777, true);
        $pdo = new \PDO('sqlite:' . $dir . '/storage/app.sqlite');
        /** @var Migration $migration */
        $migration = require dirname(__DIR__, 2) . '/migrations/2026_10_23_000001_auth_remember.php';
        $migration->up(new Schema($pdo));
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/data.php', "<?php\nreturn ['drivers' => ['sqlite' => ['path' => '{$dir}/storage/app.sqlite']], 'default' => 'sqlite', 'aliases' => ['auth' => 'sqlite']];\n");
        file_put_contents($dir . '/config/auth.php', "<?php\nreturn ["
            . "'default_guard' => 'session',"
            . "'guards' => ["
            . "'session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config'],"
            . "'member' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config', 'remember' => ['cookie' => 'ss_member', 'days' => 30, 'path' => '/']],"
            . "],"
            . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
            . "'session' => ['cookie' => 'remember_boot', 'paths' => ['/members']],"
            . "];\n");

        $app = Application::create($dir);
        $app->boot();
        $guard = $app->container()->get(AuthManager::class)->guard('member');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $request = (new Psr17Factory())->createServerRequest('GET', '/members')->withAttribute(SessionInterface::class, new ArraySession());
        $guard->login(new TestUser(uuid: 'user-1'), $request, true);

        $cookies = $guard->pullCookies();
        $this->assertCount(1, $cookies);
        $this->assertMatchesRegularExpression('/^ss_member=[^;]+; Max-Age=2592000; Path=\/; HttpOnly; SameSite=Lax; Secure$/', $cookies[0]);
        $this->assertSame('member', $pdo->query('SELECT guard FROM auth_remember')->fetchColumn());

        // The staff guard has no remember support; a public page gets no cookie.
        $staff = $app->container()->get(AuthManager::class)->guard('session');
        $staff->login(new TestUser(uuid: 'user-1'), $request, true);
        $this->assertSame([], $staff->pullCookies());
        $response = $app->handle((new Psr17Factory())->createServerRequest('GET', '/hello')->withCookieParams(['ss_member' => explode(';', substr($cookies[0], 10))[0]]));
        $this->assertSame([], $response->getHeader('Set-Cookie'));
    }
}
