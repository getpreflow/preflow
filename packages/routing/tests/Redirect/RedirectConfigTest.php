<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests\Redirect;

use PHPUnit\Framework\TestCase;
use Preflow\Routing\Redirect\RedirectConfig;

final class RedirectConfigTest extends TestCase
{
    public function test_defaults_without_file(): void
    {
        $config = RedirectConfig::fromFile(sys_get_temp_dir() . '/no-such-redirects-' . bin2hex(random_bytes(4)) . '.php');

        $this->assertTrue($config->recordNotFound);
        $this->assertSame(1000, $config->notFoundLimit);
        $this->assertSame([], $config->ignore);
    }

    public function test_reads_all_keys(): void
    {
        $config = $this->load("['not_found' => false, 'not_found_limit' => 50, 'ignore' => ['/legacy/', '.json']]");

        $this->assertFalse($config->recordNotFound);
        $this->assertSame(50, $config->notFoundLimit);
        $this->assertSame(['/legacy/', '.json'], $config->ignore);
    }

    public function test_limit_below_one_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not_found_limit');

        $this->load("['not_found_limit' => 0]");
    }

    private function load(string $array): RedirectConfig
    {
        $file = sys_get_temp_dir() . '/redirects_config_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\nreturn {$array};\n");

        try {
            return RedirectConfig::fromFile($file);
        } finally {
            unlink($file);
        }
    }
}
