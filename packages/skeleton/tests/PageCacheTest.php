<?php

declare(strict_types=1);

namespace Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * The skeleton as a new site gets it, in production: the page cache is on, and its public pages
 * are stored (sessions only on its sign-in and admin paths).
 */
final class PageCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/skeleton_pc_' . bin2hex(random_bytes(4));
        $skeleton = dirname(__DIR__);
        mkdir($this->dir . '/storage', 0777, true);
        foreach (['app', 'config', 'lang', 'public'] as $folder) {
            exec('cp -R ' . escapeshellarg($skeleton . '/' . $folder) . ' ' . escapeshellarg($this->dir . '/' . $folder));
        }
        // With the reason a miss was not stored (explain), for the messages below.
        $config = (string) file_get_contents($this->dir . '/config/page_cache.php');
        file_put_contents($this->dir . '/config/page_cache.php', str_replace("// 'explain' => false,", "'explain' => true,", $config));
        putenv('APP_DEBUG=0');
        putenv('APP_KEY=skeleton-page-cache-test-key-0123456789');
        putenv('APP_URL=http://localhost:8080');
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        putenv('APP_KEY');
        putenv('APP_URL');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_public_pages_are_stored_and_sign_in_pages_have_a_session(): void
    {
        foreach (['/about'] as $path) {
            $miss = $this->get($path);
            $this->assertSame(200, $miss->getStatusCode(), $path . ': ' . substr((string) $miss->getBody(), 0, 300));
            $this->assertSame('miss', $miss->getHeaderLine('X-Page-Cache'), $path);
            $this->assertSame('', $miss->getHeaderLine('X-Page-Cache-Skip'), $path);
            $this->assertSame('hit', $this->get($path)->getHeaderLine('X-Page-Cache'), $path . ' is stored');
        }

        // The home page shows the error boundary demo (a component that throws): never stored.
        $this->assertSame('refused:component-error', $this->get('/')->getHeaderLine('X-Page-Cache-Skip'));

        // The sign-in page keeps its session (its form, its flash messages), so it is never stored.
        $app = $this->app();
        $login = $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost:8080/login'));
        $this->assertSame(200, $login->getStatusCode());
        $this->assertTrue($app->container()->get(\Preflow\Core\Http\Session\SessionInterface::class)->isStarted());
        $this->assertNotSame('hit', $this->get('/login')->getHeaderLine('X-Page-Cache'));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->setRunningInConsole(false);
        $app->boot();

        return $app;
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost:8080' . $path));
    }
}
