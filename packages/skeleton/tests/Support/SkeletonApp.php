<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Preflow\Core\Application;
use Preflow\Testing\Fast\TestDataPaths;
use Preflow\Testing\TestResponse;

/**
 * Boots the real app on a copy of the project, with its own storage, so tests never touch
 * storage/data. vendor/ is linked, not copied.
 */
trait SkeletonApp
{
    /** Never copied: the code runs from the project, the copy only holds config, templates and data. */
    private const array NOT_COPIED = ['vendor', 'storage', 'tests', '.env', '.git'];

    private string $appRoot = '';

    private function bootApp(): void
    {
        $project = dirname(__DIR__, 2);
        $this->appRoot = sys_get_temp_dir() . '/skeleton_' . bin2hex(random_bytes(4));
        mkdir($this->appRoot . '/storage/cache', 0777, true);
        mkdir($this->appRoot . '/storage/logs', 0777, true);

        foreach (scandir($project) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::NOT_COPIED, true)) {
                continue;
            }
            exec('cp -R ' . escapeshellarg($project . '/' . $entry) . ' ' . escapeshellarg($this->appRoot . '/' . $entry));
        }
        // The project's packages, as the app has them (core reads vendor/composer/installed.json).
        symlink($project . '/vendor', $this->appRoot . '/vendor');

        // Clean globals, as a new process on the server has.
        $_COOKIE = $_GET = $_POST = $_REQUEST = $_FILES = [];

        $this->setEnv('APP_KEY', 'skeleton-test-key-0123456789abcdef');
        $this->setEnv('APP_URL', 'http://localhost:8080');
        $this->setEnv('APP_DEBUG', '1');
        TestDataPaths::use($this->appRoot);
    }

    private function removeApp(): void
    {
        foreach (['APP_KEY', 'APP_URL', 'APP_DEBUG'] as $name) {
            putenv($name);
            unset($_ENV[$name]);
        }
        TestDataPaths::release();
        if ($this->appRoot !== '' && is_dir($this->appRoot)) {
            exec('rm -rf ' . escapeshellarg($this->appRoot));
        }
        $this->appRoot = '';
    }

    /** One freshly booted app per request, like production. */
    private function get(string $uri): TestResponse
    {
        $app = Application::create($this->appRoot);
        $app->boot();

        return new TestResponse($app->handle((new Psr17Factory())->createServerRequest('GET', $uri)));
    }

    private function setEnv(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}
