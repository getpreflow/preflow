<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Core\Application;
use Preflow\Core\Routing\RouterInterface;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Folio\Console\ExtensionsApplyCommand;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\Customization\Customizations;
use Preflow\Folio\Extension\Screen\ScreenRegistry;
use Preflow\Folio\Http\AdminNav;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio's admin setup on demand (Application::onRequest()): a web request outside the admin
 * prefix builds no admin nav, screens, customizations or admin routes; the first request under
 * the prefix sets them all up, the same as a console boot (which sets them up at boot). A site
 * with Folio, rbac (a screen), members and codes, from their real composer.json.
 */
final class AdminOnDemandTest extends TestCase
{
    private const string ADMIN_EMAIL = 'admin@site.test';
    private const string PASSWORD = 'secret';
    private const array PACKAGES = ['folio', 'rbac', 'members', 'codes', 'validation', 'i18n', 'media'];
    /** What a public GET must not build. */
    private const array ADMIN_SERVICES = [
        AdminNav::class,
        ScreenRegistry::class,
        Customizations::class,
        \Preflow\Folio\Http\AdminAssetUrls::class,
        \Preflow\Ai\Ai::class,
    ];

    private string $dir = '';
    private string|false $errorLog = false;
    private ?Application $flow = null;

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        ini_set('error_log', (string) $this->errorLog);
        if ($this->dir === '' || !is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_a_public_get_builds_no_admin_setup(): void
    {
        $this->site();
        $this->flow = $this->app(console: false);
        $this->assertNotAdmin('after boot');

        $this->assertSame(200, $this->request('GET', '/')->getStatusCode());
        $this->assertSame(404, $this->request('GET', '/no-such-page')->getStatusCode());
        $this->assertNotAdmin('after public requests');
    }

    #[RunInSeparateProcess]
    public function test_the_permission_registry_answers_on_a_public_request(): void
    {
        $this->site();
        $this->flow = $this->app(console: false);
        $this->assertSame(200, $this->request('GET', '/')->getStatusCode());

        $access = $this->flow->container()->get(Access::class);
        foreach (['folio.dashboard', 'post.edit', 'assets.view', 'member.view', 'code.view'] as $permission) {
            $this->assertTrue($access->registry()->isDeclared($permission), $permission);
            $this->assertFalse($access->can($permission), $permission . ' for a visitor');
        }
        $this->assertNotAdmin('after can()');
    }

    #[RunInSeparateProcess]
    public function test_an_admin_request_after_a_public_one_gets_all_admin_routes(): void
    {
        $this->site();
        $console = $this->app(console: true);
        $expectedRoutes = $this->routes($console);
        $expectedNav = $this->navItems($console);
        $this->assertContains('GET /folio', $this->patterns($console));
        $this->assertContains('GET /folio/rbac/new-permissions', $this->patterns($console), 'the rbac screen');

        $this->flow = $this->app(console: false);
        $this->assertSame(200, $this->request('GET', '/')->getStatusCode());
        $this->assertSame(200, $this->request('GET', '/folio/login')->getStatusCode());

        $this->assertSame($expectedRoutes, $this->routes($this->flow), 'the same routes, in the same order');
        $patterns = $this->patterns($this->flow);
        $frontend = array_search('GET /{...path}', $patterns, true);
        $lastAdmin = max(array_keys(array_filter($patterns, static fn (string $p): bool => str_contains($p, ' /folio'))));
        $this->assertGreaterThan($lastAdmin, $frontend, 'the admin routes come before the frontend catch-all');
        $this->assertSame($expectedNav, $this->navItems($this->flow));

        // Set up once: a second admin request adds nothing.
        $this->assertSame(200, $this->request('GET', '/folio/login')->getStatusCode());
        $this->assertSame($expectedRoutes, $this->routes($this->flow));
    }

    #[RunInSeparateProcess]
    public function test_every_admin_screen_answers_for_an_admin(): void
    {
        $this->site();
        $this->flow = $this->app(console: false);
        $this->assertSame(200, $this->request('GET', '/')->getStatusCode());
        $this->signIn();

        $types = array_map(static fn ($l): string => $l->key, $this->flow->container()->get(TypeCatalog::class)->all());
        $walked = [];
        foreach ($this->flow->container()->get(RouterInterface::class)->getCollection()->forMethod('GET') as $route) {
            $pattern = $route->pattern;
            if (!str_starts_with($pattern, '/folio') || str_contains($pattern, '{id}') || str_contains($pattern, '{key}')
                || preg_match('~/(_assets|_uploads|_relation|_export|_targets|_ai|_account|login|forgot|password)\b~', $pattern) === 1) {
                continue;
            }
            foreach (str_contains($pattern, '{type}') ? $types : [''] as $type) {
                $uri = str_replace('{type}', $type, $pattern);
                $status = $this->request('GET', $uri)->getStatusCode();
                $this->assertLessThan(500, $status, $uri); // a singleton type has no "new" form: 404
                if ($status === 200) {
                    $walked[] = $uri;
                }
            }
        }
        foreach (['/folio', '/folio/assets', '/folio/extensions', '/folio/cache', '/folio/translations', '/folio/redirects', '/folio/seo-audit', '/folio/rbac_role', '/folio/member', '/folio/code', '/folio/post/new'] as $must) {
            $this->assertContains($must, $walked);
        }
    }

    /** 0.58 served `'path' => 'folio'` (no leading slash) at /folio, as the route builders normalise the prefix. */
    #[RunInSeparateProcess]
    public function test_a_prefix_without_a_leading_slash_serves_the_admin(): void
    {
        $this->site('folio');
        $this->flow = $this->app(console: false);
        $this->assertSame(200, $this->request('GET', '/')->getStatusCode());
        $this->assertNotAdmin('after a public request');

        $this->assertSame(200, $this->request('GET', '/folio/login')->getStatusCode());
        $this->assertContains('GET /folio', $this->patterns($this->flow));
        $this->assertSame(200, $this->request('GET', '/de/folio/login')->getStatusCode(), 'behind the locale prefix');

        $container = $this->flow->container();
        $this->assertTrue($container->get(\Preflow\Core\Http\NonPublicPaths::class)->matches('/folio/post'), 'the admin is not a public page');
        $this->assertTrue($container->get(\Preflow\Core\Http\Csrf\CsrfSignInPaths::class)->matches('/folio/login'), 'the staff login is a sign-in path');
    }

    /** A setup that threw (a broken extension screen, a passing outage) is not marked done: the next admin request runs it again. */
    #[RunInSeparateProcess]
    public function test_an_admin_setup_that_failed_runs_again_on_the_next_admin_request(): void
    {
        $this->site();
        $this->flow = $this->app(console: false);
        $container = $this->flow->container();
        $bindings = (new \ReflectionProperty($container, 'bindings'))->getValue($container);
        $real = $bindings[ScreenRegistry::class];
        $fail = true;
        $container->singleton(ScreenRegistry::class, static function (mixed ...$args) use (&$fail, $real): ScreenRegistry {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('screen validation failed');
            }

            return $real(...$args);
        });

        try {
            $status = $this->request('GET', '/folio/login')->getStatusCode();
        } catch (\RuntimeException $e) {
            $this->assertSame('screen validation failed', $e->getMessage());
            $status = 500;
        }
        $this->assertSame(500, $status, 'the failed setup');
        $this->assertFalse($fail);

        $this->assertSame(200, $this->request('GET', '/folio/login')->getStatusCode(), 'set up on the retry');
        $this->assertContains('GET /folio', $this->patterns($this->flow));
        $this->assertContains('GET /folio/rbac/new-permissions', $this->patterns($this->flow), 'the rbac screen');
    }

    #[RunInSeparateProcess]
    public function test_cli_boot_has_the_admin_setup(): void
    {
        $this->site();
        $app = $this->app(console: true);

        $this->assertContains('GET /folio', $this->patterns($app));
        foreach ([AdminNav::class, ScreenRegistry::class, Customizations::class, \Preflow\Ai\Ai::class] as $service) {
            $this->assertTrue($app->container()->resolved($service), $service);
        }
    }

    #[RunInSeparateProcess]
    public function test_component_endpoint_builds_admin_service_only_when_used(): void
    {
        $this->site();
        $console = $this->app(console: true);
        $this->flow = $this->app(console: false);

        $this->assertLessThan(500, $this->request('GET', '/--component/action?token=nope')->getStatusCode());
        $this->assertNotAdmin('after a component request');

        // A component that needs an admin service gets it built, complete, on use.
        $this->assertSame($this->navItems($console), $this->navItems($this->flow));
        $this->assertTrue($this->flow->container()->resolved(AdminNav::class));
        $this->assertNotContains('GET /folio', $this->patterns($this->flow), 'no admin routes without an admin request');
    }

    private function assertNotAdmin(string $when): void
    {
        $container = $this->flow->container();
        foreach (self::ADMIN_SERVICES as $service) {
            $this->assertFalse($container->resolved($service), "{$service} {$when}");
        }
        $admin = array_filter($this->routes($this->flow), static fn (string $route): bool => str_contains($route, ' /folio'));
        $this->assertSame([], array_values($admin), "admin routes {$when}");
    }

    /** @return list<string> method, pattern and handler of every route, in order */
    private function routes(Application $app): array
    {
        return array_map(
            static fn ($e): string => $e->method . ' ' . $e->pattern . ($e->pattern === '/{...path}' ? '' : ' ' . $e->handler . ' ' . implode(',', $e->middleware)),
            $app->container()->get(RouterInterface::class)->getCollection()->all(),
        );
    }

    /** @return list<string> method and pattern of every route */
    private function patterns(Application $app): array
    {
        return array_map(static fn ($e): string => $e->method . ' ' . $e->pattern, $app->container()->get(RouterInterface::class)->getCollection()->all());
    }

    /** @return list<string> the items added to the sidebar (Folio's settings and the extension screens) */
    private function navItems(Application $app): array
    {
        $nav = $app->container()->get(AdminNav::class);

        return array_map(
            static fn (array $item): string => $item['section'] . ' ' . $item['url'] . ' ' . $item['permission'] . ' ' . $item['order'],
            (new \ReflectionProperty($nav, 'added'))->getValue($nav),
        );
    }

    private function site(string $prefix = '/folio'): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/folio_on_demand_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log');
        $this->write('config/app.php', "<?php\nreturn ['name' => 'On demand', 'debug' => 0, 'engine' => 'twig', 'key' => 'on-demand-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, \\Preflow\\Members\\MembersServiceProvider::class, \\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => " . var_export($prefix, true) . "];\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => [
                'session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff'],
                'members' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
            ],
            'providers' => [
                'staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active'],
                'config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => self::ADMIN_EMAIL, 'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'roles' => ['admin']],
                ]],
                'users' => ['class' => \Preflow\Auth\ChainUserProvider::class, 'providers' => ['staff', 'config']],
                'members' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'member', 'email' => 'email', 'password' => 'password_hash', 'roles' => 'roles'],
            ],
            'session' => ['cookie' => 'on_demand_test', 'path' => '/', 'paths' => ['/folio', '/members'], 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
                'sqlite' => ['path' => $this->dir . '/storage/site.sqlite'],
            ],
            'aliases' => ['members' => 'sqlite', 'auth' => 'sqlite', 'throttle' => 'sqlite', 'codes' => 'sqlite'],
        ], true) . ";\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix', 'cookie' => false];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'hello@site.test']]];\n");
        $this->write('config/codes.php', "<?php\nreturn ['purposes' => ['members.book' => ['label' => 'Book code', 'readable' => true]]];\n");
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Posts', 'publishing' => true,
            'fields' => ['title' => ['type' => 'string'], 'cover' => ['type' => 'asset'], 'body' => ['type' => 'richtext']],
        ]));
        $packages = [];
        foreach (self::PACKAGES as $name) {
            $root = dirname(__DIR__, 3) . '/' . $name;
            $packages[] = [...json_decode((string) file_get_contents($root . '/composer.json'), true), 'install-path' => $root];
        }
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages]));
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}, "rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}, "codes": {"disabled_parts": []}}}');
        $this->write('app/pages/index.twig', 'HOME');

        foreach ([new MigrateCommand(fn () => $this->app(console: true)), new ExtensionsApplyCommand(fn () => $this->app(console: true))] as $command) {
            ob_start();
            try {
                $code = $command->execute([]);
            } finally {
                $out = (string) ob_get_clean();
            }
            $this->assertSame(0, $code, $out);
        }
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** A booted app: as in the console (admin set up at boot), or as on the web. */
    private function app(bool $console): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole($console);
        $app->boot();

        return $app;
    }

    /** @param array<string, string> $body */
    private function request(string $method, string $uri, array $body = []): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, $uri, ['REMOTE_ADDR' => '203.0.113.7']);
        if ($method !== 'GET') {
            $request = $request->withHeader('Sec-Fetch-Site', 'same-origin');
            if (session_id() !== '') {
                $request = $request->withCookieParams([session_name() => session_id()]);
            }
        }
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        return $this->flow->handle($method === 'POST' ? $request->withParsedBody($body) : $request);
    }

    private function signIn(): void
    {
        if (!isset($_SESSION['_csrf_token'])) {
            $this->request('GET', '/folio/login');
        }
        $response = $this->request('POST', '/folio/login', ['email' => self::ADMIN_EMAIL, 'password' => self::PASSWORD, '_csrf_token' => (string) ($_SESSION['_csrf_token'] ?? '')]);
        $this->assertSame('/folio', $response->getHeaderLine('Location'));
    }
}
