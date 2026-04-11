<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;

final class ApplicationEnvTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/preflow_app_env_test_' . uniqid();
        mkdir($this->tmpDir);
        mkdir($this->tmpDir . '/config');
    }

    protected function tearDown(): void
    {
        foreach (['APP_NAME', 'APP_DEBUG', 'APP_KEY', 'APP_TIMEZONE', 'APP_LOCALE'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        @unlink($this->tmpDir . '/.env');
        @unlink($this->tmpDir . '/config/app.php');
        @rmdir($this->tmpDir . '/config');
        @rmdir($this->tmpDir);
    }

    public function test_create_loads_env_file_before_config(): void
    {
        file_put_contents($this->tmpDir . '/.env', "APP_NAME=FromEnv\nAPP_DEBUG=2\n");
        file_put_contents($this->tmpDir . '/config/app.php', <<<'PHP'
        <?php
        return [
            'name' => getenv('APP_NAME') ?: 'Default',
            'debug' => (int) (getenv('APP_DEBUG') ?: 0),
        ];
        PHP);

        $app = Application::create($this->tmpDir);

        $this->assertSame('FromEnv', $app->config()->get('app.name'));
        $this->assertSame(2, $app->config()->get('app.debug'));
    }

    public function test_create_works_without_env_file(): void
    {
        file_put_contents($this->tmpDir . '/config/app.php', <<<'PHP'
        <?php
        return [
            'name' => 'Hardcoded',
            'debug' => 0,
        ];
        PHP);

        $app = Application::create($this->tmpDir);

        $this->assertSame('Hardcoded', $app->config()->get('app.name'));
    }
}
