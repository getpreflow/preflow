<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Http\Session;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Session\SessionDataGuard;

final class SessionDataGuardTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_hide_and_restore(): void
    {
        $_SESSION = ['_auth_user_id' => 'member'];
        $guard = new SessionDataGuard();

        $guard->hide();
        $this->assertTrue($guard->hiding());
        $this->assertSame(['_flash' => ['current' => [], 'previous' => []]], $_SESSION);

        $guard->hide(); // nested: the outer hide owns the data
        $guard->restore();
        $this->assertTrue($guard->hiding());

        $guard->restore();
        $this->assertFalse($guard->hiding());
        $this->assertSame(['_auth_user_id' => 'member'], $_SESSION);
    }

    #[RunInSeparateProcess]
    public function test_without_a_session_nothing_is_left_behind(): void
    {
        unset($_SESSION);
        $guard = new SessionDataGuard();

        $guard->hide();
        $_SESSION = ['written' => 'by the render'];
        $guard->restore();

        $this->assertFalse(isset($_SESSION));
    }

    #[RunInSeparateProcess]
    public function test_shutdown_hook_restores_an_unfinished_hide(): void
    {
        $_SESSION = ['_auth_user_id' => 'member'];
        $guard = new SessionDataGuard();
        $guard->hide();
        $guard->hide();

        $guard->onShutdown();

        $this->assertFalse($guard->hiding());
        $this->assertSame(['_auth_user_id' => 'member'], $_SESSION);
    }

    public function test_session_is_written_with_the_visitors_data_when_the_render_never_returns(): void
    {
        $dir = sys_get_temp_dir() . '/sess_guard_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $id = 'guard' . bin2hex(random_bytes(8));

        try {
            $cmd = [PHP_BINARY, __DIR__ . '/../../Fixtures/SessionGuard/fatal_during_render.php',
                dirname(__DIR__, 5) . '/vendor/autoload.php', $dir, $id];
            exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $code);

            $this->assertSame(0, $code, implode("\n", $output));
            $written = (string) @file_get_contents($dir . '/sess_' . $id);
            $this->assertStringContainsString('_auth_user_id|s:6:"member"', $written);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }
}
