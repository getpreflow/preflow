<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/HeaderGuard.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Boot\ManifestBuilder;
use Preflow\Core\Tests\Fixtures\HeaderGuard;
use Preflow\Core\Routing\RouterInterface;

/**
 * Route middleware with arguments (MiddlewareWith): AuthMiddleware for a second guard, next to
 * the plain class name for the default one, served live and from the boot manifest.
 */
final class RouteMiddlewareArgumentsBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mw_args_' . bin2hex(random_bytes(4));
        $this->write('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'mw-args-test-key-0123456789abcdef'];\n");
        $this->write('app/pages/index.twig', '<html><head></head><body>home</body></html>');
        $provider = static fn (string $email): array => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [['email' => $email, 'password_hash' => '', 'roles' => []]]];
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'staff',
            'guards' => [
                'staff' => ['class' => HeaderGuard::class, 'provider' => 'staff', 'population' => 'staff', 'login' => '/login'],
                'employers' => ['class' => HeaderGuard::class, 'provider' => 'employers', 'population' => 'members', 'login' => '/employer/login'],
            ],
            'providers' => ['staff' => $provider('admin@site.test'), 'employers' => $provider('boss@firm.test')],
        ], true) . ";\n");
        $this->write('app/Controllers/EmployerController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Nyholm\Psr7\Response;
            use Preflow\Auth\Http\AuthMiddleware;
            use Preflow\Routing\Attributes\Get;
            use Preflow\Routing\Attributes\Middleware;
            use Preflow\Routing\Attributes\Route;
            use Preflow\Routing\MiddlewareWith;

            #[Route('/employer')]
            final class EmployerController
            {
                #[Get('/jobs')]
                #[Middleware(new MiddlewareWith(AuthMiddleware::class, guard: 'employers'))]
                public function jobs(): Response
                {
                    return new Response(200, [], 'jobs');
                }

                #[Get('/staff')]
                #[Middleware(AuthMiddleware::class)]
                public function staff(): Response
                {
                    return new Response(200, [], 'staff');
                }
            }
            PHP);
        $this->write('app/Controllers/FirmController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Nyholm\Psr7\Response;
            use Preflow\Auth\Http\AuthMiddleware;
            use Preflow\Routing\Attributes\Get;
            use Preflow\Routing\Attributes\Route;
            use Preflow\Routing\MiddlewareWith;

            #[Route('/firm', middleware: [new MiddlewareWith(AuthMiddleware::class, guard: 'employers')])]
            final class FirmController
            {
                #[Get('/')]
                public function index(): Response
                {
                    return new Response(200, [], 'firm');
                }
            }
            PHP);
        $dir = $this->dir;
        spl_autoload_register(static function (string $class) use ($dir): void {
            if (str_starts_with($class, 'App\\Controllers\\')) {
                require $dir . '/app/Controllers/' . substr($class, 16) . '.php';
            }
        });
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
    public function test_the_guard_argument_protects_the_route_on_the_second_guard(): void
    {
        $this->assertGuarded($this->web());
    }

    #[RunInSeparateProcess]
    public function test_routes_from_the_boot_manifest_keep_the_arguments(): void
    {
        $result = (new ManifestBuilder($this->dir))->write();
        $this->assertTrue($result['written']);
        $this->assertContains('app', $result['routes']);

        $app = $this->web();
        $this->assertTrue($app->bootManifest()->active());
        $this->assertGuarded($app);
        $this->assertNotContains('app', $app->container()->get(RouterInterface::class)->liveSets(), 'the routes came from the manifest');
    }

    private function assertGuarded(Application $app): void
    {
        $employer = $this->get($app, '/employer/jobs', 'boss@firm.test');
        $this->assertSame(200, $employer->getStatusCode());
        $this->assertSame('jobs', (string) $employer->getBody());

        $staff = $this->get($app, '/employer/jobs', 'admin@site.test');
        $this->assertSame(302, $staff->getStatusCode(), 'a default-guard user is no employer');
        $this->assertSame('/employer/login?next=%2Femployer%2Fjobs', $staff->getHeaderLine('Location'));

        // #[Route(middleware: [...])]
        $this->assertSame('firm', (string) $this->get($app, '/firm', 'boss@firm.test')->getBody());
        $this->assertSame('/employer/login?next=%2Ffirm', $this->get($app, '/firm', 'admin@site.test')->getHeaderLine('Location'));

        // A plain class name: the default guard, as before.
        $this->assertSame('staff', (string) $this->get($app, '/employer/staff', 'admin@site.test')->getBody());
        $this->assertSame('/login?next=%2Femployer%2Fstaff', $this->get($app, '/employer/staff', 'boss@firm.test')->getHeaderLine('Location'));
    }

    private function get(Application $app, string $path, string $email): \Psr\Http\Message\ResponseInterface
    {
        $id = (string) (new \Preflow\Auth\ConfigUserProvider([['email' => $email, 'password_hash' => '']]))->findByCredentials(['email' => $email])?->getAuthId();

        return $app->handle((new Psr17Factory())->createServerRequest('GET', $path)->withHeader('X-User', $id));
    }

    private function web(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    private function write(string $rel, string $content): void
    {
        @mkdir(dirname($this->dir . '/' . $rel), 0777, true);
        file_put_contents($this->dir . '/' . $rel, $content);
    }
}
