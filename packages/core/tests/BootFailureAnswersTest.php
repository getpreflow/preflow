<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A crash while booting (a wrong provider line), an Error escaping run() or a fatal error answers
 * with an error page, through a web SAPI (php-cgi, and PHP's built-in server), the way a visitor
 * meets it; the site's public/index.php is the starters' own lines.
 */
final class BootFailureAnswersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bootfail_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/public', 0777, true);
        file_put_contents($this->dir . '/public/index.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            require getenv('PREFLOW_AUTOLOAD') ?: $_SERVER['PREFLOW_AUTOLOAD'];
            $app = Preflow\Core\Application::create(__DIR__ . '/..');
            $app->boot();
            $app->run();
            PHP);
        $this->provider('$container->noSuchMethod();');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** A provider whose boot() runs $body ($container is the container). */
    private function provider(string $body): void
    {
        file_put_contents($this->dir . '/BrokenProvider.php', <<<PHP
            <?php
            final class BrokenProvider extends Preflow\\Core\\Container\\ServiceProvider
            {
                public function register(Preflow\\Core\\Container\\Container \$container): void {}
                public function boot(Preflow\\Core\\Container\\Container \$container): void
                {
                    {$body}
                }
            }
            PHP);
        file_put_contents($this->dir . '/config/providers.php', "<?php\nrequire __DIR__ . '/../BrokenProvider.php';\nreturn [BrokenProvider::class];\n");
    }

    private function config(int $debug): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'key'=>'boot-fail-test-key-0123456789abcdef'];\n");
    }

    private function autoload(): string
    {
        return dirname(__DIR__, 3) . '/vendor/autoload.php';
    }

    /** @return array{status: int, headers: string, body: string} */
    private function cgi(int $displayErrors = 1, int $buffering = 4096, string $disable = ''): array
    {
        // CI runs only tools/tests, so this skip shows on a machine without php-cgi, never hides a CI run.
        if (trim((string) shell_exec('command -v php-cgi')) === '') {
            $this->markTestSkipped('php-cgi is not installed: the web-SAPI failure tests cannot run.');
        }
        $process = proc_open(
            ['php-cgi', '-q', '-d', 'display_errors=' . $displayErrors, '-d', 'html_errors=0', '-d', 'disable_functions=' . $disable, '-d', 'output_buffering=' . $buffering, '-d', 'error_log=' . $this->dir . '/php.log', '-f', $this->dir . '/public/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REDIRECT_STATUS' => '200', 'SCRIPT_FILENAME' => $this->dir . '/public/index.php', 'PREFLOW_AUTOLOAD' => $this->autoload(), 'PATH' => (string) getenv('PATH')],
        );
        $out = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        [$headers, $body] = array_pad(explode("\r\n\r\n", $out, 2), 2, '');
        preg_match('/^Status: (\d+)/mi', $headers, $m);

        return ['status' => (int) ($m[1] ?? 200), 'headers' => $headers, 'body' => $body];
    }

    public function test_a_provider_that_throws_in_boot_answers_500_without_a_trace_when_debug_is_off(): void
    {
        $this->config(0);

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('text/html', $res['headers']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);
        $this->assertStringNotContainsString('noSuchMethod', $res['body']);
        $this->assertStringNotContainsString('Stack trace', $res['body']);
        $this->assertStringNotContainsString('BrokenProvider', $res['body']);
        $log = (string) file_get_contents($this->dir . '/php.log');
        $this->assertStringContainsString('noSuchMethod', $log);
        $this->assertStringContainsString('Stack trace', $log, 'the log keeps the trace PHP used to write');
    }

    public function test_the_same_crash_shows_the_dev_error_page_when_debug_is_on(): void
    {
        $this->config(1);

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('noSuchMethod', $res['body']);
        $this->assertStringNotContainsString('Fatal error: Uncaught', $res['body']);
    }

    public function test_output_printed_before_the_crash_does_not_silence_the_error(): void
    {
        $this->config(1);
        $this->provider('echo "partial output"; $container->noSuchMethod();');

        $res = $this->cgi(1, 0);

        $this->assertStringContainsString('partial output', $res['body']);
        $this->assertStringContainsString('noSuchMethod', $res['body'], 'the dev page is still printed once headers are sent');
    }

    /** @since 0.84.2 config/app.php is what failed, so APP_DEBUG from the environment decides */
    public function test_a_broken_config_file_shows_the_dev_page_when_app_debug_is_on(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => ;\n");
        file_put_contents($this->dir . '/.env', "APP_DEBUG=1\n");

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('syntax error', $res['body'], 'the dev page names the parse error');
    }

    public function test_a_broken_config_file_shows_the_plain_page_without_app_debug(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => ;\n");

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        $this->assertStringNotContainsString('syntax error', $res['body']);
    }

    /** @since 0.84.2 HTML appended to a truncated JSON or download would only break its client */
    public function test_output_that_is_not_html_gets_no_error_page_appended(): void
    {
        $this->config(1);
        $this->provider('header("Content-Type: application/json"); echo "{\"items\":["; $container->noSuchMethod();');

        $res = $this->cgi(1, 0);

        $this->assertSame('{"items":[', $res['body']);
        $this->assertStringContainsString('noSuchMethod', (string) file_get_contents($this->dir . '/php.log'), 'still logged');
    }

    public function test_an_error_escaping_run_after_a_good_boot_answers_500(): void
    {
        $this->config(0);
        $this->provider('$container->get(Preflow\Core\Application::class)->onRequest(static function (): void { throw new \Error("late failure"); });');

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        $this->assertStringNotContainsString('late failure', $res['body']);
    }

    public function test_an_http_exception_keeps_its_status(): void
    {
        $this->config(0);
        $this->provider('$container->get(Preflow\Core\Application::class)->onRequest(static function (): void { throw new Preflow\Core\Exceptions\HttpException(503, "Back soon"); });');

        $res = $this->cgi();

        $this->assertSame(503, $res['status']);
        $this->assertStringContainsString('Service Unavailable', $res['body']);
    }

    public function test_an_exception_handler_set_before_create_still_runs_after_the_answer(): void
    {
        $this->config(0);
        $index = (string) file_get_contents($this->dir . '/public/index.php');
        $marker = $this->dir . '/tracker.txt';
        file_put_contents($this->dir . '/public/index.php', str_replace(
            '$app = ',
            'set_exception_handler(static function (Throwable $e): void { file_put_contents(' . var_export($marker, true) . ', get_class($e)); echo "tracker noise"; });' . "\n" . '$app = ',
            $index,
        ));

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        $this->assertStringNotContainsString('tracker noise', $res['body']);
        $this->assertSame('Error', (string) @file_get_contents($marker));
    }

    public function test_a_chained_handler_that_exits_cannot_leak_its_output(): void
    {
        $this->config(0);
        $index = (string) file_get_contents($this->dir . '/public/index.php');
        file_put_contents($this->dir . '/public/index.php', str_replace(
            '$app = ',
            'set_exception_handler(static function (Throwable $e): void { echo "SECRET TRACE " . $e->getTraceAsString(); exit(1); });' . "\n" . '$app = ',
            $index,
        ));

        $res = $this->cgi();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        $this->assertStringNotContainsString('SECRET TRACE', $res['body']);
    }

    public function test_when_building_the_answer_fails_the_fallback_is_still_a_500(): void
    {
        $this->config(0);

        // A host that disables header_remove(): sending the answer throws, the fallback must still be a 500.
        $res = $this->cgi(1, 4096, 'header_remove');

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
    }

    public function test_a_fatal_error_answers_500_with_a_page_when_php_shows_no_errors(): void
    {
        $this->config(0);
        file_put_contents($this->dir . '/config/providers.php', "<?php\nini_set('memory_limit', '16M');\n\$x = str_repeat('x', 256 * 1024 * 1024);\nreturn [];\n");

        $res = $this->cgi(0);

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body'], 'a page, not an empty 500');
        $this->assertStringNotContainsString('Fatal error', $res['body']);
    }

    public function test_a_failing_boot_still_throws_in_the_console(): void
    {
        $this->config(0);
        $out = (string) shell_exec(sprintf('PREFLOW_AUTOLOAD=%s php %s 2>&1', escapeshellarg($this->autoload()), escapeshellarg($this->dir . '/public/index.php')));

        $this->assertStringContainsString('noSuchMethod', $out);
    }

    public function test_the_built_in_server_answers_500_with_the_dev_page(): void
    {
        $this->config(1);
        $php = PHP_BINARY;
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            $this->markTestSkipped('No free local port for the built-in server: ' . $errstr);
        }
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);
        $process = proc_open(
            [$php, '-S', '127.0.0.1:' . $port, '-t', $this->dir . '/public', $this->dir . '/public/index.php'],
            [1 => ['file', $this->dir . '/server.log', 'w'], 2 => ['file', $this->dir . '/server.log', 'a']],
            $pipes,
            null,
            ['PREFLOW_AUTOLOAD' => $this->autoload(), 'PATH' => (string) getenv('PATH')],
        );
        try {
            $body = false;
            for ($i = 0; $i < 40 && $body === false; $i++) {
                usleep(100000);
                $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]));
            }
            $this->assertNotFalse($body, 'the built-in server did not answer');
            $status = (int) substr((string) ($http_response_header[0] ?? ''), 9, 3);
            $this->assertSame(500, $status);
            $this->assertStringContainsString('noSuchMethod', (string) $body);
            $this->assertStringNotContainsString('Fatal error: Uncaught', (string) $body);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }
}
