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

    /**
     * A site whose key is weak (production refuses to serve with it) fixes itself from the console:
     * key:generate runs (the console boots with a weak key) and writes a key that can sign.
     */
    public function test_key_generate_runs_with_a_weak_key_and_writes_one_that_can_sign(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_KEY=change-this-to-a-random-32-char-string!\n");
        mkdir($this->tmpDir . '/config');
        file_put_contents($this->tmpDir . '/config/app.php', "<?php\nreturn ['name' => 'k', 'debug' => 0, 'key' => 'change-this-to-a-random-32-char-string!'];\n");
        $cwd = getcwd();
        chdir($this->tmpDir);
        try {
            ob_start();
            $code = (new \Preflow\DevTools\Console($this->tmpDir))->run(['preflow', 'key:generate']);
            ob_end_clean();
        } finally {
            chdir((string) $cwd);
        }

        $this->assertSame(0, $code);
        preg_match('/^APP_KEY=(.+)$/m', (string) file_get_contents($this->tmpDir . '/.env'), $matches);
        $this->assertTrue(\Preflow\Core\Security\AppKey::canSign($matches[1] ?? ''), 'the new key can sign');
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
