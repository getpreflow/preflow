<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Console;
use Preflow\DevTools\Tests\Fixtures\HelloCommand;

/** Apps (and packages such as Folio) add commands by listing them in config/commands.php. */
final class ConsoleAppCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/console_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/config/commands.php');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_console_registers_app_listed_commands(): void
    {
        file_put_contents($this->dir . '/config/commands.php', '<?php return [' . HelloCommand::class . '::class, "No\\\\Such\\\\Command", \\stdClass::class];');
        $stderr = fopen('php://memory', 'w+');

        $console = new Console($this->dir, $stderr);
        ob_start();
        $code = $console->run(['preflow', 'fixture:hello']);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame("hello\n", $out);
        rewind($stderr);
        $warnings = (string) stream_get_contents($stderr);
        $this->assertStringContainsString('No\\Such\\Command', $warnings);
        $this->assertStringContainsString('stdClass', $warnings);
    }

    public function test_no_commands_file_is_fine(): void
    {
        ob_start();
        $code = (new Console($this->dir))->run(['preflow', 'help']);
        ob_end_clean();

        $this->assertSame(0, $code);
    }
}
