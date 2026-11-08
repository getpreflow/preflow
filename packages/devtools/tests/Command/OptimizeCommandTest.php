<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\OptimizeCommand;
use Preflow\DevTools\Console;

/** `preflow optimize [--check]`: writes the boot manifest, reports drift. */
final class OptimizeCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/optimize_cmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/.env', '');
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['debug' => 0, 'key' => 'optimize-test-key'];\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    /** @return array{0: int, 1: string} */
    private function optimize(array $args): array
    {
        ob_start();
        $code = (new OptimizeCommand($this->dir))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    public function test_optimize_writes_the_manifest(): void
    {
        [$code, $out] = $this->optimize([]);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Boot manifest written: ' . realpath($this->dir) . '/bootstrap/cache/boot.php', $out);
        $this->assertFileExists($this->dir . '/bootstrap/cache/boot.php');
    }

    public function test_optimize_check_reports_drift(): void
    {
        [$code, $out] = $this->optimize(['--check']);
        $this->assertSame(1, $code);
        $this->assertSame("No boot manifest (run preflow optimize).\n", $out);

        $this->optimize([]);
        [$code, $out] = $this->optimize(['--check']);
        $this->assertSame(0, $code, $out);
        $this->assertSame("Boot manifest is current.\n", $out);

        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['debug' => 0, 'key' => 'optimize-test-key', 'name' => 'Changed'];\n");
        [$code, $out] = $this->optimize(['--check']);
        $this->assertSame(1, $code);
        $this->assertSame("Boot manifest is out of date (run preflow optimize):\n  config: changed\n", $out);
    }

    public function test_debug_on_writes_no_manifest(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['debug' => 1, 'key' => 'optimize-test-key'];\n");

        [$code, $out] = $this->optimize([]);

        $this->assertSame(0, $code, 'a deploy step does not fail');
        $this->assertStringStartsWith('No boot manifest written: APP_DEBUG is on', $out);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
    }

    public function test_unknown_arguments_are_refused(): void
    {
        [$code, $out] = $this->optimize(['--force']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown argument: --force', $out);
        $this->assertFileDoesNotExist($this->dir . '/bootstrap/cache/boot.php');
    }

    public function test_the_console_lists_it(): void
    {
        ob_start();
        (new Console($this->dir))->run(['preflow', 'help']);
        $this->assertStringContainsString('optimize', (string) ob_get_clean());
    }
}
