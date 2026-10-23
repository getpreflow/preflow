<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Console;
use Preflow\DevTools\Tests\Fixtures\HelloCommand;

/** Installed packages add commands with extra.preflow.commands in their composer.json: no config/commands.php line needed. */
final class ConsolePackageCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/console_pkg_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/vendor/composer/installed.json');
        @rmdir($this->dir . '/vendor/composer');
        @rmdir($this->dir . '/vendor');
        @rmdir($this->dir);
    }

    public function test_console_registers_commands_of_installed_packages(): void
    {
        file_put_contents($this->dir . '/vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'a/plain'],
            ['name' => 'a/with', 'extra' => ['preflow' => ['commands' => [HelloCommand::class, 'No\\Such\\Command']]]],
        ]]));
        $stderr = fopen('php://memory', 'w+');

        ob_start();
        $code = (new Console($this->dir, $stderr))->run(['preflow', 'fixture:hello']);
        $out = ob_get_clean();

        $this->assertSame(0, $code);
        $this->assertSame("hello\n", $out);
        rewind($stderr);
        $this->assertStringContainsString('No\\Such\\Command', (string) stream_get_contents($stderr));
    }

    public function test_unreadable_installed_json_is_fine(): void
    {
        file_put_contents($this->dir . '/vendor/composer/installed.json', '{nope');

        ob_start();
        $code = (new Console($this->dir))->run(['preflow', 'help']);
        ob_end_clean();

        $this->assertSame(0, $code);
    }
}
