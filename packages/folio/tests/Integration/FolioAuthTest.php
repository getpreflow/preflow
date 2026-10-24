<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

final class FolioAuthTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_auth_' . bin2hex(random_bytes(4));
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();
        return $app;
    }

    #[RunInSeparateProcess]
    public function test_unauthenticated_admin_redirects_to_login(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio'));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_login_page_is_public_and_has_csrf_field(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/login'));
        $body = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('name="email"', $body);
    }

    #[RunInSeparateProcess]
    public function test_login_page_has_a_brand_scene_and_an_intact_form(): void
    {
        $body = (string) $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/login'))->getBody();

        $this->assertMatchesRegularExpression('#<aside class="folio-auth-art" aria-hidden="true">#', $body); // decorative only
        $this->assertStringContainsString('class="folio-auth-pattern"', $body);
        $this->assertStringContainsString('class="folio-logo"', $body);                                       // the Folio mark
        $this->assertMatchesRegularExpression('#<h1[^>]*>[^<]*Sign in#', $body);
        foreach (['name="email"', 'name="password"', 'name="_csrf_token"', 'autocomplete="current-password"'] as $needle) {
            $this->assertStringContainsString($needle, $body);
        }
    }

    /**
     * Characterisation: a site whose users come from config/auth.php only (no record users, no
     * remember-me) gets the login page byte for byte as before the staff accounts (CSRF token,
     * CSP nonce, asset version and the debug toolbar are the parts that differ per request or build).
     */
    #[RunInSeparateProcess]
    public function test_login_page_of_a_config_user_site_is_unchanged(): void
    {
        $body = (string) $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/login'))->getBody();
        $body = (string) preg_replace(
            ['~name="_csrf_token" value="[^"]*"~', '~nonce="[^"]*"~', '~(admin\.css)\?v=[^"]*~', '~<div id="preflow-debug-toolbar">.*(?=</body>)~s'],
            ['name="_csrf_token" value=""', 'nonce=""', '$1?v=', ''],
            $body,
        );
        // Recorded from the login page before the staff accounts (v0.26).
        $this->assertSame((string) file_get_contents(dirname(__DIR__) . '/Fixtures/login-config-site.html'), $body);
    }

    #[RunInSeparateProcess]
    public function test_a_config_user_site_has_no_password_pages(): void
    {
        $app = $this->app();
        $f = new Psr17Factory();

        // As before: /folio/forgot is an admin URL (a type list) behind the login, /folio/password/x matches nothing.
        $forgot = $app->handle($f->createServerRequest('GET', '/folio/forgot'));
        $this->assertSame(302, $forgot->getStatusCode());
        $this->assertSame('/folio/login', $forgot->getHeaderLine('Location'));
        $this->assertSame(404, $app->handle($f->createServerRequest('GET', '/folio/password/some-token'))->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_a_config_user_site_with_remember_me_offers_it_without_password_pages(): void
    {
        $auth = require $this->dir . '/config/auth.php';
        $auth['guards']['session']['remember'] = ['cookie' => 'folio_remember', 'path' => '/folio'];
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export($auth, true) . ";\n");
        $app = $this->app();
        $f = new Psr17Factory();

        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        $this->assertStringContainsString('name="remember" value="1"', $page);
        $this->assertStringNotContainsString('/folio/forgot', $page, 'no record users: no forgot link');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $m[1] ?? '', 'email' => 'admin@site.test', 'password' => 'secret', 'remember' => '1',
        ]));

        $this->assertSame('/folio', $login->getHeaderLine('Location'));
        $this->assertMatchesRegularExpression('~^folio_remember=[^;]+; Max-Age=~', implode("\n", $login->getHeader('Set-Cookie')));
    }

    #[RunInSeparateProcess]
    public function test_assets_are_public_while_unauthenticated(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));
        $this->assertSame(200, $res->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_login_then_reach_dashboard_then_logout(): void
    {
        $app = $this->app();
        $f = new Psr17Factory();

        // Fetch the login page to obtain the CSRF token.
        $loginPage = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $loginPage, $m);
        $token = $m[1] ?? '';
        $this->assertNotSame('', $token);

        // Submit correct credentials.
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
        $this->assertSame('/folio', $login->getHeaderLine('Location'));

        // Now the dashboard is reachable (session persists in-process).
        $dash = $app->handle($f->createServerRequest('GET', '/folio'));
        $this->assertSame(200, $dash->getStatusCode());

        // Logout, then the admin redirects again.
        $out = $app->handle($f->createServerRequest('POST', '/folio/logout')->withParsedBody(['_csrf_token' => $token]));
        $this->assertSame(302, $out->getStatusCode());
        $this->assertSame('/folio/login', $out->getHeaderLine('Location'));
    }

    private function scaffold(): void
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };

        $w('config/app.php', "<?php\nreturn ['name'=>'Auth Test','debug'=>1,'engine'=>'twig','key'=>'auth-test-key'];\n");
        $w('config/data.php', "<?php\nreturn ['drivers'=>['json'=>['driver'=>\\Preflow\\Data\\Driver\\JsonFileDriver::class,'path'=>__DIR__.'/../storage/data']],'default'=>'json','models_path'=>__DIR__.'/models'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn ['path'=>'/folio'];\n");
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,
            'session' => ['lifetime' => 7200, 'cookie' => 'folio_auth_test', 'path' => '/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'],
        ], true) . ";\n");
        $w('config/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string', 'validate' => ['required']], 'slug' => ['type' => 'string', 'validate' => ['required']], 'status' => ['type' => 'string']],
        ], JSON_PRETTY_PRINT));
        $w('app/pages/index.twig', 'HOME');
        @mkdir($this->dir . '/storage/data', 0777, true);
    }
}
