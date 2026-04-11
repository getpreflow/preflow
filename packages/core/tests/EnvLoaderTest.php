<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\EnvLoader;

final class EnvLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_env_test_' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Clean up env vars set during tests
        foreach (['TEST_NAME', 'TEST_DEBUG', 'TEST_KEY', 'TEST_QUOTED', 'TEST_SINGLE', 'TEST_EMPTY', 'TEST_EQUALS', 'TEST_INLINE', 'TEST_EXISTING', 'TEST_SPACES'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        @unlink($this->tmpDir . '/.env');
        @rmdir($this->tmpDir);
    }

    public function test_parses_simple_key_value(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_NAME=MyApp\nTEST_DEBUG=1\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('MyApp', getenv('TEST_NAME'));
        $this->assertSame('1', getenv('TEST_DEBUG'));
        $this->assertSame('MyApp', $_ENV['TEST_NAME']);
    }

    public function test_parses_double_quoted_values(): void
    {
        file_put_contents($this->tmpDir . '/.env', 'TEST_QUOTED="Hello World"' . "\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('Hello World', getenv('TEST_QUOTED'));
    }

    public function test_parses_single_quoted_values(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_SINGLE='Hello World'\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('Hello World', getenv('TEST_SINGLE'));
    }

    public function test_skips_comments_and_blank_lines(): void
    {
        $content = "# This is a comment\n\nTEST_KEY=value\n  # indented comment\n";
        file_put_contents($this->tmpDir . '/.env', $content);

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('value', getenv('TEST_KEY'));
    }

    public function test_handles_empty_values(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_EMPTY=\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('', getenv('TEST_EMPTY'));
    }

    public function test_handles_equals_in_value(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_EQUALS=abc=def=ghi\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('abc=def=ghi', getenv('TEST_EQUALS'));
    }

    public function test_strips_inline_comments(): void
    {
        file_put_contents($this->tmpDir . '/.env', "TEST_INLINE=value # this is a comment\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('value', getenv('TEST_INLINE'));
    }

    public function test_does_not_overwrite_existing_env(): void
    {
        putenv('TEST_EXISTING=original');
        file_put_contents($this->tmpDir . '/.env', "TEST_EXISTING=overwritten\n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('original', getenv('TEST_EXISTING'));
    }

    public function test_silent_noop_when_file_missing(): void
    {
        // Should not throw
        EnvLoader::load($this->tmpDir . '/.env.nonexistent');

        $this->assertTrue(true); // no exception = pass
    }

    public function test_trims_whitespace_around_key_and_value(): void
    {
        file_put_contents($this->tmpDir . '/.env', "  TEST_SPACES  =  hello  \n");

        EnvLoader::load($this->tmpDir . '/.env');

        $this->assertSame('hello', getenv('TEST_SPACES'));
    }
}
