<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\DevTools\Console;

final class ConsoleTest extends TestCase
{
    public function test_help_returns_zero(): void
    {
        $console = new Console();
        ob_start();
        $code = $console->run(['preflow', 'help']);
        ob_end_clean();

        $this->assertSame(0, $code);
    }

    public function test_unknown_command_returns_one(): void
    {
        $console = new Console();
        ob_start();
        $code = $console->run(['preflow', 'nonexistent']);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    public function test_all_commands_registered(): void
    {
        $console = new Console();
        // Just verify construction doesn't throw
        $this->assertInstanceOf(Console::class, $console);
    }

    public function test_make_component_without_name_fails(): void
    {
        $cmd = new \Preflow\DevTools\Command\MakeComponentCommand();
        ob_start();
        $code = $cmd->execute([]);
        ob_end_clean();

        $this->assertSame(1, $code);
    }

    public function test_make_component_creates_files(): void
    {
        $tmpDir = sys_get_temp_dir() . '/preflow_cli_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $originalDir = getcwd();
        chdir($tmpDir);

        $cmd = new \Preflow\DevTools\Command\MakeComponentCommand();
        ob_start();
        $code = $cmd->execute(['TestWidget']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($tmpDir . '/app/Components/TestWidget/TestWidget.php');
        $this->assertFileExists($tmpDir . '/app/Components/TestWidget/TestWidget.twig');

        chdir($originalDir);
        // Cleanup
        $this->deleteDir($tmpDir);
    }

    public function test_make_model_creates_file(): void
    {
        $tmpDir = sys_get_temp_dir() . '/preflow_cli_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $originalDir = getcwd();
        chdir($tmpDir);

        $cmd = new \Preflow\DevTools\Command\MakeModelCommand();
        ob_start();
        $code = $cmd->execute(['BlogPost']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $this->assertFileExists($tmpDir . '/app/Models/BlogPost.php');

        $content = file_get_contents($tmpDir . '/app/Models/BlogPost.php');
        $this->assertStringContainsString('blog_posts', $content); // table name

        chdir($originalDir);
        $this->deleteDir($tmpDir);
    }

    public function test_make_migration_creates_file(): void
    {
        $tmpDir = sys_get_temp_dir() . '/preflow_cli_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $originalDir = getcwd();
        chdir($tmpDir);

        $cmd = new \Preflow\DevTools\Command\MakeMigrationCommand();
        ob_start();
        $code = $cmd->execute(['create_users']);
        ob_end_clean();

        $this->assertSame(0, $code);
        $files = glob($tmpDir . '/migrations/*create_users.php');
        $this->assertCount(1, $files);

        chdir($originalDir);
        $this->deleteDir($tmpDir);
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
