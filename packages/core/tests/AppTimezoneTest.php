<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

/** app.timezone is the zone for wall-clock values (e.g. Folio's "Publish at"), on the web and in the CLI. */
final class AppTimezoneTest extends TestCase
{
    private string $previous;

    protected function setUp(): void
    {
        $this->previous = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previous);
    }

    public function test_app_timezone_is_applied_on_boot(): void
    {
        $dir = sys_get_temp_dir() . '/tz_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','timezone'=>'Pacific/Kiritimati'];\n");
        date_default_timezone_set('UTC');

        try {
            Application::create($dir)->boot();
            $this->assertSame('Pacific/Kiritimati', date_default_timezone_get());
        } finally {
            @unlink($dir . '/config/app.php');
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
    }

    public function test_invalid_timezone_is_ignored(): void
    {
        $dir = sys_get_temp_dir() . '/tz_' . bin2hex(random_bytes(4));
        mkdir($dir . '/config', 0777, true);
        file_put_contents($dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','timezone'=>'Mars/Olympus'];\n");
        date_default_timezone_set('UTC');

        try {
            Application::create($dir)->boot();
            $this->assertSame('UTC', date_default_timezone_get());
        } finally {
            @unlink($dir . '/config/app.php');
            @rmdir($dir . '/config');
            @rmdir($dir);
        }
    }
}
