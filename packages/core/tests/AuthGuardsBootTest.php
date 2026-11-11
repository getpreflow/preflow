<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/HeaderGuard.php';
require_once __DIR__ . '/Fixtures/ConfiguredGuard.php';
require_once __DIR__ . '/Fixtures/PipelineCounter/PipelineCounter.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;
use Preflow\Core\Tests\Fixtures\HeaderGuard;
use Preflow\Core\Tests\Fixtures\PipelineCounter\PipelineCounter;
use Preflow\Htmx\ComponentToken;

/** Guards from config/auth.php: custom classes, a members guard, resolution order. */
final class AuthGuardsBootTest extends TestCase
{
    private string $dir;

    private const KEY = 'guards-test-key-0123456789abcdef00';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guards_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'" . self::KEY . "'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>{{ can("x") ? "yes" : "no" }}</body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /**
     * @param array<string, array<string, mixed>> $guards
     * @param array<string, mixed> $more more config/auth.php keys
     */
    private function boot(array $guards, string $default = 'staff', array $more = []): Application
    {
        $users = [['id' => 'admin', 'email' => 'admin@site.test', 'password_hash' => '', 'roles' => []],
                  ['id' => 'member', 'email' => 'member@site.test', 'password_hash' => '', 'roles' => []]];
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export($more + [
            'default_guard' => $default,
            'guards' => $guards,
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users]],
            'session' => ['cookie' => 'guards_test', 'secure' => false],
        ], true) . ";\n");
        $app = Application::create($this->dir);
        $app->boot();
        $app->container()->get(Access::class)->registry()->declare('x');

        return $app;
    }

    private function idOf(string $email): string
    {
        return (string) (new \Preflow\Auth\ConfigUserProvider([['email' => $email, 'password_hash' => '']]))->findByCredentials(['email' => $email])?->getAuthId();
    }

    #[RunInSeparateProcess]
    public function test_custom_guard_class_is_built_from_config(): void
    {
        $app = $this->boot(['staff' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'staff']]);
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/')->withHeader('X-User', $this->idOf('admin@site.test')));

        $this->assertStringContainsString('yes', (string) $res->getBody());
        $this->assertSame('staff', $app->container()->get(AuthManager::class)->guardName());
    }

    #[RunInSeparateProcess]
    public function test_each_guard_s_provider_is_on_the_auth_manager(): void
    {
        $app = $this->boot([
            'staff' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'staff'],
            'members' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'members'],
        ]);
        $auth = $app->container()->get(AuthManager::class);

        $this->assertInstanceOf(\Preflow\Auth\ConfigUserProvider::class, $auth->provider('members'));
        $this->assertSame($app->container()->get(\Preflow\Auth\UserProviderInterface::class), $auth->provider());
        $this->assertNull($auth->provider('nope'));
    }

    #[RunInSeparateProcess]
    public function test_non_guard_class_fails_boot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->boot(['staff' => ['class' => \stdClass::class, 'provider' => 'users']]);
    }

    #[RunInSeparateProcess]
    public function test_members_user_is_resolved_and_population_is_members(): void
    {
        $app = $this->boot([
            'staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff'],
            'members' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'members'],
        ]);
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/')->withHeader('X-User', $this->idOf('member@site.test')));

        $this->assertStringContainsString('no', (string) $res->getBody()); // members: named permissions denied by default
        $this->assertSame('members', $app->container()->get(AuthManager::class)->guardName());
    }

    #[RunInSeparateProcess]
    public function test_default_guard_wins_when_both_are_signed_in(): void
    {
        $app = $this->boot([
            'members' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'members'],
            'staff' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'staff'],
        ], default: 'staff');
        $app->handle((new Psr17Factory())->createServerRequest('GET', '/')->withHeader('X-User', $this->idOf('admin@site.test')));

        $this->assertSame('staff', $app->container()->get(AuthManager::class)->guardName());
    }

    #[RunInSeparateProcess]
    public function test_can_twig_function_exists_without_auth_config(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/'));

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('yes', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_signed_in_user_is_visible_to_guarded_components(): void
    {
        $app = $this->boot(['staff' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'staff']]);
        $token = (new ComponentToken(self::KEY))->encode(PipelineCounter::class, [], 'bump');
        $app->handle((new Psr17Factory())->createServerRequest('POST', '/--component/action')
            ->withParsedBody(['token' => $token])->withHeader('X-User', $this->idOf('admin@site.test')));

        $this->assertNotNull(PipelineCounter::$seen?->getAttribute(\Preflow\Auth\Authenticatable::class));
    }

    #[RunInSeparateProcess]
    public function test_auth_middleware_admits_only_the_default_guard(): void
    {
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [\\Preflow\\Auth\\Http\\AuthMiddleware::class];\n");
        $app = $this->boot([
            'staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff'],
            'members' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'members'],
        ]);
        $member = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('X-User', $this->idOf('member@site.test'));

        $this->assertSame(401, $app->handle($member)->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_container_built_auth_middleware_redirects_html_to_the_guards_login(): void
    {
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [\\Preflow\\Auth\\Http\\AuthMiddleware::class];\n");
        $app = $this->boot(['staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff', 'login' => '/login']]);
        $factory = new Psr17Factory();

        $html = $app->handle($factory->createServerRequest('GET', '/?a=b'));
        $this->assertSame(302, $html->getStatusCode());
        $this->assertSame('/login?next=%2F%3Fa%3Db', $html->getHeaderLine('Location'));

        $json = $app->handle($factory->createServerRequest('GET', '/api/x'));
        $this->assertSame(401, $json->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_guest_middleware_ignores_other_guards(): void
    {
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [\\Preflow\\Auth\\Http\\GuestMiddleware::class];\n");
        $app = $this->boot([
            'staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff'],
            'members' => ['class' => HeaderGuard::class, 'provider' => 'users', 'population' => 'members'],
        ]);
        $member = (new Psr17Factory())->createServerRequest('GET', '/')->withHeader('X-User', $this->idOf('member@site.test'));

        $this->assertSame(200, $app->handle($member)->getStatusCode()); // a member is still a guest for the staff login
    }

    public function test_auth_middleware_for_a_named_guard(): void
    {
        $auth = new AuthManager(['staff' => new HeaderGuard(new \Preflow\Auth\ConfigUserProvider([])), 'members' => new HeaderGuard(new \Preflow\Auth\ConfigUserProvider([['email' => 'm@site.test', 'password_hash' => '']]))], 'staff');
        $auth->setUser(new \Preflow\Auth\ArrayUser('m@site.test', 'm@site.test', '', []), 'members');
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $r): \Psr\Http\Message\ResponseInterface { return new \Nyholm\Psr7\Response(204); }
        };

        $res = (new \Preflow\Auth\Http\AuthMiddleware($auth, 'members'))->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);
        $this->assertSame(204, $res->getStatusCode());

        $this->expectException(\Preflow\Core\Exceptions\UnauthorizedHttpException::class);
        (new \Preflow\Auth\Http\AuthMiddleware($auth))->process((new Psr17Factory())->createServerRequest('GET', '/'), $handler);
    }

    #[RunInSeparateProcess]
    public function test_a_custom_guard_gets_its_entry_and_is_built_after_the_providers_on_first_use(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . \Preflow\Core\Tests\Fixtures\GuardTestProvider::class . "::class];\n");
        \Preflow\Core\Tests\Fixtures\ConfiguredGuard::$built = 0;
        $app = $this->boot(['api' => ['class' => \Preflow\Core\Tests\Fixtures\ConfiguredGuard::class, 'provider' => 'users', 'population' => 'staff', 'header' => 'X-Api-User']], default: 'api');

        $this->assertSame(0, \Preflow\Core\Tests\Fixtures\ConfiguredGuard::$built, 'not built during boot');
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/')->withHeader('X-Api-User', $this->idOf('admin@site.test')));

        $this->assertStringContainsString('yes', (string) $res->getBody());
        $guard = $app->container()->get(AuthManager::class)->guard('api');
        $this->assertInstanceOf(\Preflow\Core\Tests\Fixtures\ConfiguredGuard::class, $guard, 'the guard itself, not a stand-in');
        $this->assertSame(1, \Preflow\Core\Tests\Fixtures\ConfiguredGuard::$built, 'built once');
        $this->assertSame('X-Api-User', $guard->config['header']);
        $this->assertSame('users', $guard->config['provider']);
        $this->assertSame('t', $guard->appName);
        $this->assertSame($app->container()->get(\Preflow\Core\Tests\Fixtures\GuardAudit::class), $guard->audit);
        $this->assertSame([$this->idOf('admin@site.test')], $guard->audit->seen);
    }

    #[RunInSeparateProcess]
    public function test_the_password_hasher_is_a_binding_an_app_provider_replaces(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . \Preflow\Core\Tests\Fixtures\GuardTestProvider::class . "::class];\n");
        \Preflow\Core\Tests\Fixtures\GuardTestProvider::$bindHasher = true;
        $users = [['email' => 'a@site.test', 'password_hash' => 'app:secret', 'roles' => []]];
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'staff',
            'guards' => ['staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users]],
        ], true) . ";\n");
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $this->assertInstanceOf(\Preflow\Core\Tests\Fixtures\PlainPrefixHasher::class, $c->get(\Preflow\Auth\PasswordHasherInterface::class));
        $this->assertTrue($c->get(AuthManager::class)->guard('staff')->validate(['email' => 'a@site.test', 'password' => 'secret']), 'the session guard uses the app\'s hasher');
        $this->assertFalse($c->get(AuthManager::class)->guard('staff')->validate(['email' => 'a@site.test', 'password' => 'wrong']));
    }

    #[RunInSeparateProcess]
    public function test_the_configured_password_hasher_is_built_by_the_container(): void
    {
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . \Preflow\Core\Tests\Fixtures\GuardTestProvider::class . "::class];\n");
        $users = [['email' => 'a@site.test', 'password_hash' => 'audited:secret', 'roles' => []]];
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'staff',
            'guards' => ['staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => $users]],
            'password_hasher' => \Preflow\Core\Tests\Fixtures\AuditedHasher::class,
        ], true) . ";\n");
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $this->assertTrue($c->get(AuthManager::class)->guard('staff')->validate(['email' => 'a@site.test', 'password' => 'secret']));
        $this->assertSame(['verify'], $c->get(\Preflow\Core\Tests\Fixtures\GuardAudit::class)->seen, 'its constructor got the provider\'s service');
    }

    #[RunInSeparateProcess]
    public function test_a_missing_guard_class_fails_boot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('App\\Nope');
        $this->boot(['staff' => ['class' => 'App\\Nope', 'provider' => 'users']]);
    }
}
