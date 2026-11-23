<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\ServeCommand;

final class ServeCommandTest extends TestCase
{
    public function test_paths_with_spaces_are_quoted(): void
    {
        $cmd = (new ServeCommand())->buildCommand('localhost', 8080, '/tmp/my app/public', '/x/router.php');

        $this->assertStringContainsString("'/tmp/my app/public'", $cmd);
        $this->assertStringContainsString("'localhost:8080'", $cmd);
        $this->assertStringContainsString("'/x/router.php'", $cmd);
    }

    public function test_a_free_port_has_no_problem(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url((string) stream_socket_get_name($server, false), PHP_URL_PORT);
        fclose($server);

        $this->assertNull((new ServeCommand())->portProblem('127.0.0.1', $port));
    }

    public function test_a_taken_port_names_the_port_flag_on_one_line(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url((string) stream_socket_get_name($server, false), PHP_URL_PORT);

        $problem = (new ServeCommand())->portProblem('127.0.0.1', $port);
        fclose($server);

        $this->assertNotNull($problem);
        $this->assertStringContainsString("127.0.0.1:{$port}", $problem);
        $this->assertStringContainsString('--port=', $problem);
        $this->assertStringNotContainsString("\n", $problem);
    }

    public function test_serve_exits_non_zero_without_starting_when_the_port_is_taken(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url((string) stream_socket_get_name($server, false), PHP_URL_PORT);
        $ran = null;
        $dir = sys_get_temp_dir() . '/serve_' . bin2hex(random_bytes(4));
        mkdir($dir . '/public', 0777, true);
        $previous = getcwd();
        chdir($dir);
        ob_start();
        try {
            $code = (new ServeCommand(static function (string $command) use (&$ran): int {
                $ran = $command;

                return 0;
            }))->execute(['--host=127.0.0.1', "--port={$port}"]);
            $out = (string) ob_get_clean();
        } finally {
            chdir($previous);
            fclose($server);
            rmdir($dir . '/public');
            rmdir($dir);
        }

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('started', $out);
        $this->assertNull($ran, 'the server is never run on a taken port');
    }

    /** @since 0.84.2 */
    #[DataProvider('badPorts')]
    public function test_serve_rejects_a_port_outside_1_to_65535_naming_port_without_running(string $port): void
    {
        $ran = null;
        $dir = sys_get_temp_dir() . '/serve_' . bin2hex(random_bytes(4));
        mkdir($dir . '/public', 0777, true);
        $previous = getcwd();
        chdir($dir);
        ob_start();
        try {
            $code = (new ServeCommand(static function (string $command) use (&$ran): int {
                $ran = $command;

                return 0;
            }))->execute(["--port={$port}"]);
            $out = (string) ob_get_clean();
        } finally {
            chdir($previous);
            rmdir($dir . '/public');
            rmdir($dir);
        }

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('started', $out);
        $this->assertNull($ran);
    }

    /** @return array<string, array{string}> */
    public static function badPorts(): array
    {
        return ['zero' => ['0'], 'letters' => ['abc'], 'too high' => ['65536'], 'negative' => ['-1'], 'empty' => ['']];
    }

    public function test_a_bad_port_message_is_one_line_naming_the_flag(): void
    {
        $problem = (new ServeCommand())->portProblem('localhost', 0);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('--port=', $problem);
        $this->assertStringNotContainsString("\n", $problem);
    }

    public function test_a_free_port_runs_the_server_through_the_runner(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url((string) stream_socket_get_name($server, false), PHP_URL_PORT);
        fclose($server);
        $ran = null;
        $dir = sys_get_temp_dir() . '/serve_' . bin2hex(random_bytes(4));
        mkdir($dir . '/public', 0777, true);
        $previous = getcwd();
        chdir($dir);
        ob_start();
        try {
            $code = (new ServeCommand(static function (string $command) use (&$ran): int {
                $ran = $command;

                return 0;
            }))->execute(['--host=127.0.0.1', "--port={$port}"]);
            $out = (string) ob_get_clean();
        } finally {
            chdir($previous);
            rmdir($dir . '/public');
            rmdir($dir);
        }

        $this->assertSame(0, $code);
        $this->assertStringContainsString('started', $out);
        $this->assertStringContainsString("'127.0.0.1:{$port}'", (string) $ran);
    }
}
