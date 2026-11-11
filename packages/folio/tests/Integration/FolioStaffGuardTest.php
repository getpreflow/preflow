<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;

/**
 * Who may sign into Folio: staff, through folio.guard (default: the app's default guard). A guard
 * that declares another population (the plain starter's employers, members) stops the boot.
 */
final class FolioStaffGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_staff_guard_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /**
     * @param array<string, mixed> $employers more of the employers guard (the default one)
     * @param array<string, mixed> $folio     more of config/folio.php
     */
    private function site(array $employers = [], array $folio = []): Application
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name'=>'Guard Test','debug'=>1,'engine'=>'twig','key'=>'staff-guard-test-key'];\n");
        $w('config/data.php', "<?php\nreturn ['drivers'=>['json'=>['driver'=>'json','path'=>__DIR__.'/../storage/data']],'default'=>'json','models_path'=>__DIR__.'/models'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        $w('config/folio.php', "<?php\nreturn " . var_export(['path' => '/folio'] + $folio, true) . ";\n");
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'employers',
            'guards' => [
                'employers' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'employers'] + $employers,
                'staff' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'staff', 'population' => 'staff'],
            ],
            'providers' => [
                'employers' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'boss@company.test', 'password_hash' => $hash, 'roles' => ['employer']],
                ]],
                'staff' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ]],
            ],
            'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,
            'session' => ['cookie' => 'folio_guard_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $w('config/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'status' => ['type' => 'string']],
        ]));
        $w('app/pages/index.twig', 'HOME');
        @mkdir($this->dir . '/storage/data', 0777, true);

        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_a_default_guard_of_another_population_stops_the_boot(): void
    {
        $message = null;
        try {
            $this->site(['population' => 'employers']);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }
        $this->assertNotNull($message, 'Folio booted on the employers guard');
        $this->assertStringContainsString('"employers"', $message);
        $this->assertStringContainsString('population', $message);
        $this->assertStringContainsString("'population' => 'staff'", $message, 'says what to configure');
        $this->assertStringContainsString("'guard'", $message, 'names config/folio.php guard');
    }

    #[RunInSeparateProcess]
    public function test_a_members_default_guard_stops_the_boot_too(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"members"');
        $this->site(['population' => 'members']);
    }

    #[RunInSeparateProcess]
    public function test_folio_guard_naming_no_configured_guard_stops_the_boot(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"admins"');
        $this->site(folio: ['guard' => 'admins']);
    }

    #[RunInSeparateProcess]
    public function test_staff_sign_in_through_folio_guard_and_employers_stay_out(): void
    {
        $app = $this->site(['population' => 'employers'], ['guard' => 'staff']);
        $f = new Psr17Factory();
        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $token = $m[1] ?? '';

        // An employer's credentials don't open the admin: the staff guard doesn't know them.
        $refused = $app->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $token, 'email' => 'boss@company.test', 'password' => 'secret',
        ]));
        $this->assertSame('/folio/login', $refused->getHeaderLine('Location'));
        $this->assertSame(302, $app->handle($f->createServerRequest('GET', '/folio'))->getStatusCode());

        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame('/folio', $login->getHeaderLine('Location'));
        $this->assertArrayHasKey('_auth_user_id.staff', $_SESSION, 'signed in on the staff guard');
        $this->assertArrayNotHasKey('_auth_user_id', $_SESSION, 'not on the default (employers) guard');

        $dash = $app->handle($f->createServerRequest('GET', '/folio'));
        $this->assertSame(200, $dash->getStatusCode());
        $auth = $app->container()->get(AuthManager::class);
        $this->assertSame('staff', $auth->guardName());
        $this->assertSame('staff', $app->container()->get(Access::class)->populationOf('staff'));
        $this->assertSame('members', $app->container()->get(Access::class)->populationOf('employers'));
    }

    #[RunInSeparateProcess]
    public function test_an_employer_signed_in_on_the_default_guard_is_sent_to_the_login(): void
    {
        $app = $this->site(['population' => 'employers'], ['guard' => 'staff']);
        $employer = $app->container()->get(AuthManager::class)->provider('employers')?->findByCredentials(['email' => 'boss@company.test']);
        $this->assertInstanceOf(Authenticatable::class, $employer);

        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio')
            ->withAttribute(Authenticatable::class, $employer)
            ->withAttribute(AuthManager::GUARD_ATTRIBUTE, 'employers'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_default_guard_declared_staff_keeps_working(): void
    {
        $app = $this->site(['population' => 'staff']);

        $this->assertSame(302, $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio'))->getStatusCode());
        $this->assertSame('staff', $app->container()->get(Access::class)->populationOf('employers'));
    }
}
