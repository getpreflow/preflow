<?php

declare(strict_types=1);

namespace Preflow\Testing\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\ArrayUser;
use Preflow\Core\Application;
use Preflow\Testing\AuthTestHelpers;

final class AuthTestHelpersTest extends TestCase
{
    use AuthTestHelpers;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/acting_as_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'acting-as-key-0123456789abcdef0'];\n");
        file_put_contents($this->dir . '/config/middleware.php', "<?php\nreturn [\\Preflow\\Auth\\Http\\AuthMiddleware::class];\n");
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'population' => 'staff']],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'acting_as', 'secure' => false],
        ], true) . ";\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>can:{{ can("x") ? "yes" : "no" }}</body></html>');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_acting_as_reaches_can_and_auth_middleware(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $app->container()->get(Access::class)->registry()->declare('x');

        $request = $this->actingAs(new ArrayUser('staff@site.test', 'staff@site.test', '', []), (new Psr17Factory())->createServerRequest('GET', '/'));
        $res = $app->handle($request);

        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $this->assertStringContainsString('can:yes', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_without_acting_as_the_page_is_401(): void
    {
        $app = Application::create($this->dir);
        $app->boot();

        $this->assertSame(401, $app->handle((new Psr17Factory())->createServerRequest('GET', '/'))->getStatusCode());
    }
}
