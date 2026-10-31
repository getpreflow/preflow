<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\AuthManager;
use Preflow\Core\Application;

/** A session guard's remember-me cookie is Secure by the guard's setting, else the session's, else APP_SECURE. */
final class RememberCookieSecureTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/remsec_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/storage/data', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>1,'engine'=>'twig','key'=>'remember-secure-key-0123456789abcd'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data']],
        ], true) . ";\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>x</body></html>');
    }

    protected function tearDown(): void
    {
        putenv('APP_SECURE');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $remember @param array<string, mixed> $session */
    private function rememberSecure(array $remember, array $session): mixed
    {
        file_put_contents($this->dir . '/config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'members',
            'guards' => ['members' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'users', 'remember' => $remember]],
            'providers' => ['users' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => []]],
            'session' => ['cookie' => 'remsec'] + $session,
        ], true) . ";\n");
        $app = Application::create($this->dir);
        $app->boot();
        $guard = $app->container()->get(AuthManager::class)->guard('members');
        $prop = new \ReflectionProperty($guard, 'remember');

        return $prop->getValue($guard)['secure'];
    }

    #[RunInSeparateProcess]
    public function test_explicit_remember_secure_wins(): void
    {
        putenv('APP_SECURE=1');
        $this->assertFalse($this->rememberSecure(['secure' => false], ['secure' => true]));
    }

    #[RunInSeparateProcess]
    public function test_session_secure_makes_the_cookie_secure(): void
    {
        putenv('APP_SECURE');
        $this->assertTrue($this->rememberSecure(['days' => 30], ['secure' => true]));
    }

    #[RunInSeparateProcess]
    public function test_session_secure_false_beats_app_secure(): void
    {
        putenv('APP_SECURE=1');
        $this->assertFalse($this->rememberSecure(['days' => 30], ['secure' => false]));
    }

    #[RunInSeparateProcess]
    public function test_without_either_app_secure_decides(): void
    {
        putenv('APP_SECURE=1');
        $this->assertTrue($this->rememberSecure(['days' => 30], []));
    }

    #[RunInSeparateProcess]
    public function test_without_any_setting_the_cookie_is_not_secure(): void
    {
        putenv('APP_SECURE');
        $this->assertFalse($this->rememberSecure(['days' => 30], []));
    }
}
