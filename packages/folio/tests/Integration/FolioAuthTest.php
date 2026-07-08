<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
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

    /**
     * @runInSeparateProcess
     */
    public function test_unauthenticated_admin_redirects_to_login(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio'));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_login_page_is_public_and_has_csrf_field(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/login'));
        $body = (string) $res->getBody();
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="_csrf_token"', $body);
        $this->assertStringContainsString('name="email"', $body);
    }

    /**
     * @runInSeparateProcess
     */
    public function test_assets_are_public_while_unauthenticated(): void
    {
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));
        $this->assertSame(200, $res->getStatusCode());
    }

    /**
     * @runInSeparateProcess
     */
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
