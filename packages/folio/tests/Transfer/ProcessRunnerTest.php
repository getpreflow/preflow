<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Transfer\ProcessRunner;

final class ProcessRunnerTest extends TestCase
{
    public function test_streams_stdout_and_stderr_separately_and_returns_the_exit_code(): void
    {
        $out = fopen('php://temp', 'w+b');
        $err = fopen('php://temp', 'w+b');
        // 300 KB on stdout while also writing stderr: both pipes must be drained.
        $code = (new ProcessRunner())->run([PHP_BINARY, '-r', 'fwrite(STDERR, "warn\n"); echo str_repeat("x", 300000); exit(3);'], $out, $err);

        rewind($out);
        rewind($err);
        $this->assertSame(3, $code);
        $this->assertSame(300000, strlen((string) stream_get_contents($out)));
        $this->assertSame("warn\n", stream_get_contents($err));
    }
}
