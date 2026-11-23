<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Error;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Error\FailureGuard;

/** Application::create() installs the web FailureGuard by runningInConsole(), not by PHP_SAPI. */
final class FailureGuardConsoleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/guard_console_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['debug' => 0];\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/app.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_failure_guard_follows_running_in_console(): void
    {
        $app = Application::create($this->dir, console: false);

        $this->assertFalse($app->runningInConsole());
        $this->assertSame($app, FailureGuard::guarding(), 'a web app installs the guard even under the CLI SAPI');
    }

    #[RunInSeparateProcess]
    public function test_console_app_installs_no_guard(): void
    {
        $app = Application::create($this->dir, console: true);

        $this->assertTrue($app->runningInConsole());
        $this->assertNull(FailureGuard::guarding());
    }

    #[RunInSeparateProcess]
    public function test_default_follows_the_sapi(): void
    {
        Application::create($this->dir);

        $this->assertNull(FailureGuard::guarding(), 'the CLI SAPI is the console');
    }
}
