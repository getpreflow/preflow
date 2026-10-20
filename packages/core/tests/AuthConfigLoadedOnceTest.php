<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

/** config/auth.php may do expensive work (e.g. password_hash): boot must load it once. */
final class AuthConfigLoadedOnceTest extends TestCase
{
    public function test_auth_config_is_required_once_per_boot(): void
    {
        $dir = sys_get_temp_dir() . '/authonce_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
        file_put_contents($dir . '/config/auth.php', "<?php\n\$GLOBALS['auth_config_loads'] = (\$GLOBALS['auth_config_loads'] ?? 0) + 1;\nreturn ["
            . "'default_guard' => 'session',"
            . "'guards' => ['session' => ['class' => \\Preflow\\Auth\\SessionGuard::class, 'provider' => 'config']],"
            . "'providers' => ['config' => ['class' => \\Preflow\\Auth\\ConfigUserProvider::class, 'users' => []]],"
            . "'password_hasher' => \\Preflow\\Auth\\NativePasswordHasher::class,"
            . "'session' => ['name' => 'test_session'],"
            . "];\n");
        $GLOBALS['auth_config_loads'] = 0;

        try {
            Application::create($dir)->boot();
            $this->assertSame(1, $GLOBALS['auth_config_loads']);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            @unlink($dir . '/config/auth.php');
            @unlink($dir . '/config/app.php');
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
    }
}
