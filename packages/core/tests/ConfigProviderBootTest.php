<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Auth\ConfigUserProvider;
use Preflow\Auth\UserProviderInterface;
use Preflow\Core\Application;

final class ConfigProviderBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cfgboot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        // Auth config with a ConfigUserProvider and NO session block (so NativeSession
        // does not start — bootAuth still builds guards/provider/AuthManager).
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => ConfigUserProvider::class, 'users' => [
                ['email' => 'a@b.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'password_hasher' => \Preflow\Auth\NativePasswordHasher::class,
        ], true) . ";\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/app.php');
        @unlink($this->dir . '/config/auth.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_config_provider_is_wired_and_validates(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $this->assertInstanceOf(ConfigUserProvider::class, $c->get(UserProviderInterface::class));

        $guard = $c->get(AuthManager::class)->guard('session');
        $this->assertTrue($guard->validate(['email' => 'a@b.test', 'password' => 'secret']));
        $this->assertFalse($guard->validate(['email' => 'a@b.test', 'password' => 'wrong']));
    }
}
