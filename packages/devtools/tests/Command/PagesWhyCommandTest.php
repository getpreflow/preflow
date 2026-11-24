<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\DevTools\Command\PagesWhyCommand;
use Preflow\Testing\ChecksFrameBalance;

/** pages:why <path> [--locale=]: whether a page would be cached, and if not, why. */
final class PagesWhyCommandTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pages_why_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        $this->app(debug: 0);
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => true, 'verify' => 'off'];\n");
        file_put_contents($this->dir . '/app/pages/plain.twig', '<html><head><title>P</title></head><body><p>for everyone</p></body></html>');
        file_put_contents($this->dir . '/app/pages/random.twig', '<html><head><title>P</title></head><body><p>{{ random(1000000000) }}</p></body></html>');
        ini_set('error_log', $this->dir . '/php.log');
    }

    private function app(int $debug): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>{$debug},'engine'=>'twig','key'=>'pages-why-test-key-0123456789abcdef','url'=>'https://example.com'];\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param list<string> $args */
    private function run_(array $args): array
    {
        $dir = $this->dir;
        $command = new PagesWhyCommand(static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->boot();

            return $app;
        });
        ob_start();
        try {
            $code = $command->execute($args);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    #[RunInSeparateProcess]
    public function test_static_page_would_be_cached(): void
    {
        [$code, $out] = $this->run_(['/plain']);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('would be cached', $out);
    }

    #[RunInSeparateProcess]
    public function test_per_visitor_part_is_named(): void
    {
        [$code, $out] = $this->run_(['/random']);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('differs', $out);
        $this->assertStringContainsString('live block', $out);
        $this->assertStringContainsString('#[Live]', $out);
    }

    #[RunInSeparateProcess]
    public function test_works_with_debug_on_and_stores_nothing(): void
    {
        $this->app(debug: 2);

        [$code, $out] = $this->run_(['/random']);
        $this->assertSame(1, $code, $out);
        [$code, $out] = $this->run_(['/plain']);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('would be cached', $out);
        $files = 0;
        if (is_dir($this->dir . '/storage/cache/pages')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir . '/storage/cache/pages', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files += $file->isFile() ? 1 : 0;
            }
        }
        $this->assertSame(0, $files, 'nothing is stored');
    }

    #[RunInSeparateProcess]
    public function test_usage_errors_and_a_missing_page_exit_with_2(): void
    {
        foreach ([[], ['/plain', '/random'], ['/plain', '--nope'], ['/plain', '--locale=xx']] as $args) {
            [$code] = $this->run_($args);
            $this->assertSame(2, $code, implode(' ', $args));
        }
        [$code, $out] = $this->run_(['/nowhere']);
        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString('not found', $out);
    }

    #[RunInSeparateProcess]
    public function test_with_the_cache_off_it_says_so(): void
    {
        file_put_contents($this->dir . '/config/page_cache.php', "<?php\nreturn ['enabled' => false];\n");

        [$code, $out] = $this->run_(['/plain']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('page cache is off', $out);
    }
}
