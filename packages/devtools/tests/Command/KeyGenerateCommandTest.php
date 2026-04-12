<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Command\KeyGenerateCommand;

final class KeyGenerateCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_key_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->tmpDir);
    }

    public function test_generates_key_in_env_file(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_ENV=local\nAPP_KEY=change-me\nAPP_DEBUG=true\n");

        $cmd = new KeyGenerateCommand();
        ob_start();
        $code = $cmd->executeInDir($this->tmpDir);
        ob_end_clean();

        $this->assertSame(0, $code);

        $contents = file_get_contents($this->tmpDir . '/.env');
        preg_match('/^APP_KEY=(.+)$/m', $contents, $matches);
        $key = $matches[1] ?? '';

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        $this->assertStringContainsString('APP_ENV=local', $contents);
        $this->assertStringContainsString('APP_DEBUG=true', $contents);
    }

    public function test_fails_when_env_missing(): void
    {
        $cmd = new KeyGenerateCommand();
        ob_start();
        $code = $cmd->executeInDir($this->tmpDir);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    public function test_adds_key_when_empty(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=\n");

        $cmd = new KeyGenerateCommand();
        ob_start();
        $code = $cmd->executeInDir($this->tmpDir);
        ob_end_clean();

        $this->assertSame(0, $code);

        $contents = file_get_contents($this->tmpDir . '/.env');
        preg_match('/^APP_KEY=(.+)$/m', $contents, $matches);
        $key = $matches[1] ?? '';

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
