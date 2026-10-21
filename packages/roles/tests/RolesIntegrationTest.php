<?php

declare(strict_types=1);

namespace Preflow\Roles\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Auth\Tests\Fixtures\TestUser;
use Preflow\Core\Application;

final class RolesIntegrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/roles_app_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function app(bool $withRoles = true): Application
    {
        $w = function (string $rel, string $content): void {
            $path = $this->dir . '/' . $rel;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $content);
        };
        $w('config/app.php', "<?php\nreturn ['name'=>'Roles Test','debug'=>0,'engine'=>'twig','key'=>'roles-test-key'];\n");
        $w('config/providers.php', "<?php\nreturn [\\Preflow\\Roles\\RolesServiceProvider::class];\n");
        $w('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'admin',
            'guards' => ['admin' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'roles_test', 'secure' => false],
        ], true) . ";\n");
        if ($withRoles) {
            $w('config/roles.php', "<?php\nreturn ['staff' => ['editor' => ['page.*']]];\n");
        }
        $w('app/pages/index.twig', 'HOME');

        $app = Application::create($this->dir);
        $app->boot();
        $app->container()->get(Access::class)->registry()->declarePattern('page.*');
        $app->container()->get(Access::class)->registry()->declare('page.edit');
        $app->container()->get(Access::class)->registry()->declare('site.edit');

        return $app;
    }

    /**
     * @runInSeparateProcess
     */
    public function test_an_editor_is_answered_from_config_roles(): void
    {
        $app = $this->app();
        $app->container()->get(AuthManager::class)->setUser(new TestUser(roles: ['editor']), 'admin');
        $access = $app->container()->get(Access::class);

        $this->assertTrue($access->can('page.edit'));
        $this->assertFalse($access->can('site.edit'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_without_a_roles_file_staff_keep_allow_all(): void
    {
        $app = $this->app(withRoles: false);
        $app->container()->get(AuthManager::class)->setUser(new TestUser(roles: ['editor']), 'admin');

        $this->assertTrue($app->container()->get(Access::class)->can('site.edit'));
    }
}
