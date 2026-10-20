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

    public function test_broken_commands_file_does_not_take_the_cli_down(): void
    {
        file_put_contents($this->dir . '/config/commands.php', '<?php return [...\\Gone\\Package\\Commands::all()];');
        $stderr = fopen('php://memory', 'w+');

        ob_start();
        $code = (new Console($this->dir, $stderr))->run(['preflow', 'help']);
        ob_end_clean();

        $this->assertSame(0, $code);
        rewind($stderr);
        $this->assertStringContainsString('config/commands.php', (string) stream_get_contents($stderr));
    }

    public function test_command_needing_constructor_arguments_is_skipped(): void
    {
        file_put_contents($this->dir . '/config/commands.php', '<?php return [' . \Preflow\DevTools\Tests\Fixtures\NeedsArgsCommand::class . '::class];');
        $stderr = fopen('php://memory', 'w+');

        ob_start();
        $code = (new Console($this->dir, $stderr))->run(['preflow', 'help']);
        ob_end_clean();

        $this->assertSame(0, $code);
        rewind($stderr);
        $this->assertStringContainsString('NeedsArgsCommand', (string) stream_get_contents($stderr));
    }

    public function test_no_commands_file_is_fine(): void
    {
        ob_start();
        $code = (new Console($this->dir))->run(['preflow', 'help']);
        ob_end_clean();

        $this->assertSame(0, $code);
    }
}
