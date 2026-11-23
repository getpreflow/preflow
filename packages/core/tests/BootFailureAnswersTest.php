<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A crash while booting (a wrong provider line) or a fatal error answers 500 with an error page,
 * through the web SAPI (php-cgi), the way a visitor meets it; the site's public/index.php is the
 * starters' own three lines.
 */
final class BootFailureAnswersTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v php-cgi')) === '') {
            $this->markTestSkipped('php-cgi is needed to run a web request.');
        }
        $this->dir = sys_get_temp_dir() . '/bootfail_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/public', 0777, true);
        file_put_contents($this->dir . '/public/index.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            require getenv('PREFLOW_AUTOLOAD');
            $app = Preflow\Core\Application::create(__DIR__ . '/..');
            $app->boot();
            $app->run();
            PHP);
        file_put_contents($this->dir . '/BrokenProvider.php', <<<'PHP'
            <?php
            final class BrokenProvider extends Preflow\Core\Container\ServiceProvider
            {
                public function register(Preflow\Core\Container\Container $container): void {}
                public function boot(Preflow\Core\Container\Container $container): void
                {
                    $container->noSuchMethod();
                }
            }
            PHP);
        file_put_contents($this->dir . '/config/providers.php', "<?php\nrequire __DIR__ . '/../BrokenProvider.php';\nreturn [BrokenProvider::class];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function config(int $debug): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'key'=>'boot-fail-test-key-0123456789abcdef'];\n");
    }

    /** @return array{status: int, headers: string, body: string} */
    private function request(int $displayErrors = 1): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $process = proc_open(
            ['php-cgi', '-q', '-d', 'display_errors=' . $displayErrors, '-d', 'html_errors=0', '-d', 'output_buffering=4096', '-d', 'error_log=' . $this->dir . '/php.log', '-f', $this->dir . '/public/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REDIRECT_STATUS' => '200', 'SCRIPT_FILENAME' => $this->dir . '/public/index.php', 'PREFLOW_AUTOLOAD' => $autoload, 'PATH' => (string) getenv('PATH')],
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

        $res = $this->request();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('text/html', $res['headers']);
        $this->assertStringContainsString('Internal Server Error', $res['body']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);
        $this->assertStringNotContainsString('noSuchMethod', $res['body']);
        $this->assertStringNotContainsString('Stack trace', $res['body']);
        $this->assertStringNotContainsString('BrokenProvider', $res['body']);
    }

    public function test_the_same_crash_shows_the_dev_error_page_when_debug_is_on(): void
    {
        $this->config(1);

        $res = $this->request();

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('noSuchMethod', $res['body']);
        $this->assertStringNotContainsString('Fatal error: Uncaught', $res['body']);
    }

    public function test_a_fatal_error_answers_500_with_a_page_when_php_shows_no_errors(): void
    {
        $this->config(0);
        file_put_contents($this->dir . '/config/providers.php', "<?php\nini_set('memory_limit', '16M');\n\$x = str_repeat('x', 256 * 1024 * 1024);\nreturn [];\n");

        $res = $this->request(0);

        $this->assertSame(500, $res['status']);
        $this->assertStringContainsString('Internal Server Error', $res['body'], 'a page, not an empty 500');
        $this->assertStringNotContainsString('Fatal error', $res['body']);
    }

    public function test_a_failing_boot_still_throws_in_the_console(): void
    {
        $this->config(0);
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $out = (string) shell_exec(sprintf('PREFLOW_AUTOLOAD=%s php %s 2>&1', escapeshellarg($autoload), escapeshellarg($this->dir . '/public/index.php')));

        $this->assertStringContainsString('noSuchMethod', $out);
    }
}
