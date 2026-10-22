<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouteMode;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A full app with auth and preflow/roles and the Members fixture extension
 * (Fixtures/extensions/app/Members, copied to app/Folio/Members): its Approvals
 * screen lists pending members and approves one by POST.
 */
final class ScreenTest extends TestCase
{
    use PublishingScaffold;

    private const FIXTURES = __DIR__ . '/../Fixtures/extensions/app';

    private ?Application $session = null;
    private string $token = '';
    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
            ]]],
            'session' => ['cookie' => 'folio_screen_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'post.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_screen_renders_in_admin_layout_with_nav_item(): void
    {
        $this->members();
        $this->as('admin');

        $res = $this->send('GET', '/folio/approvals');
        $body = (string) $res->getBody();

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('class="folio-shell"', $body);
        $this->assertMatchesRegularExpression('#<h1>\s*Approvals\s*</h1>#', $body);
        $this->assertStringContainsString('action="/folio/approvals/m1/approve"', $body);
        $this->assertStringContainsString('name="_csrf_token" value="' . $this->token . '"', $body);
        // The sidebar item, under Settings (after the extension's settings form), active on its screen.
        $this->assertMatchesRegularExpression('#<span class="folio-nav-label">Settings</span>\s*<a href="/folio/members_settings">.*?</a>\s*<a href="/folio/approvals" class="active" aria-current="page">#s', $body);
        $this->assertStringContainsString('#ui-file"></use></svg><span>Approvals</span>', $body);

        $dashboard = (string) $this->send('GET', '/folio')->getBody();
        $this->assertStringContainsString('<a href="/folio/approvals">', $dashboard);
    }

    #[RunInSeparateProcess]
    public function test_dashboard_renders_with_extension_model(): void
    {
        $this->members(); // enables the "member" model
        $this->as('admin');

        $res = $this->send('GET', '/folio');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('href="/folio/member"', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_screen_post_requires_csrf(): void
    {
        $this->members();
        $this->as('admin');

        $without = $this->session->handle((new Psr17Factory())->createServerRequest('POST', '/folio/approvals/m1/approve')->withParsedBody([]));
        $this->assertSame(403, $without->getStatusCode());

        $with = $this->send('POST', '/folio/approvals/m1/approve');
        $this->assertSame(302, $with->getStatusCode());
        $this->assertSame('/folio/approvals?approved=m1', $with->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_screen_without_permission_is_403_and_hidden_from_nav(): void
    {
        $this->members();
        $this->as('editor');

        $res = $this->send('GET', '/folio/approvals');
        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('have access to this', (string) $res->getBody());
        $this->assertSame(403, $this->send('POST', '/folio/approvals/m1/approve')->getStatusCode());

        $list = $this->send('GET', '/folio'); // editor has folio.dashboard
        $this->assertSame(200, $list->getStatusCode());
        $this->assertStringNotContainsString('href="/folio/approvals"', (string) $list->getBody());
    }

    #[RunInSeparateProcess]
    public function test_disabled_screen_part_is_404_and_not_in_nav(): void
    {
        $this->members(['screen:approvals']);
        $this->as('admin');

        $this->assertSame(404, $this->send('GET', '/folio/approvals')->getStatusCode());
        $this->assertNotSame(302, $this->send('POST', '/folio/approvals/m1/approve')->getStatusCode());
        $this->assertStringNotContainsString('href="/folio/approvals"', (string) $this->send('GET', '/folio')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_screen_path_colliding_with_type_throws(): void
    {
        $this->clash('post');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"post"');
        $this->app();
    }

    /** @return iterable<string, array{string}> */
    public static function reservedPaths(): iterable
    {
        foreach (['login', 'logout', 'assets/library', 'extensions', 'blocks', 'media'] as $path) {
            yield $path => [$path];
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('reservedPaths')]
    public function test_screen_path_colliding_with_reserved_throws(string $path): void
    {
        $this->clash($path);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('reserved');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_screen_permission_with_placeholder_throws(): void
    {
        $this->clash('reports', '{type}.view');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"{type}.view"');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_screen_permission_checked_directly(): void
    {
        $this->clash('reports', 'reports.view');
        $this->as('editor'); // has post.view and folio.dashboard, not reports.view

        $this->assertSame(403, $this->send('GET', '/folio/reports')->getStatusCode());
        $byType = $this->send('GET', '/folio/reports/post'); // a "type" parameter naming a type the editor may view
        $this->assertSame(403, $byType->getStatusCode());
        $this->assertStringNotContainsString('clash by type', (string) $byType->getBody());
    }

    #[RunInSeparateProcess]
    public function test_unmapped_non_folio_handler_still_403(): void
    {
        $this->members(); // a screen is enabled; other handlers stay closed

        $middleware = $this->app()->container()->get(FolioPermissionMiddleware::class);
        $next = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        foreach (['App\\Controllers\\Report@index', 'App\\Folio\\Members\\Support\\Helper@index'] as $handler) {
            $request = (new Psr17Factory())->createServerRequest('GET', '/folio/report')
                ->withHeader('HX-Request', 'true')
                ->withAttribute(Route::class, new Route(RouteMode::Action, $handler));
            $this->assertSame(403, $middleware->process($request, $next)->getStatusCode(), $handler);
        }
    }

    /** @param list<string> $disabled part ids */
    private function members(array $disabled = []): void
    {
        $this->copy(self::FIXTURES . '/Members', $this->dir . '/app/Folio/Members');
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['members' => ['disabled_parts' => $disabled]]]));
    }

    /** An enabled extension "clash" whose one screen has this path and permission (GET / and GET /{type}). */
    private function clash(string $path, string $permission = 'clash.view'): void
    {
        $this->write('app/Folio/Clash/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Clash;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'clash', label: 'Clash')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Clash/Screens/Clash.php', <<<PHP
            <?php
            namespace App\\Folio\\Clash\\Screens;
            #[\\Preflow\\Folio\\Extension\\Attributes\\FolioScreen('{$path}', 'Clash', permission: '{$permission}')]
            final class Clash
            {
                #[\\Preflow\\Routing\\Attributes\\Get('/')]
                public function index(): string { return 'clash'; }

                #[\\Preflow\\Routing\\Attributes\\Get('/{type}')]
                public function byType(): string { return 'clash by type'; }
            }
            PHP);
        $this->write('config/folio/extensions.json', '{"enabled": {"clash": {"disabled_parts": []}}}');
    }

    /** Boot once; the login session lives in-process. */
    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    private function as(string $role): void
    {
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    private function send(string $method, string $uri): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token]);
        }

        return $this->session->handle($req);
    }

    private function copy(string $from, string $to): void
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $f) {
            $this->write(substr($to, strlen($this->dir) + 1) . substr($f->getPathname(), strlen($from)), (string) file_get_contents($f->getPathname()));
        }
    }
}
