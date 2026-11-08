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
/** Fails for real, unlike the home page's error demo (a preview). */
final class FailingComponent extends \Preflow\Components\Component
{
    public function resolveState(): void
    {
        throw new \RuntimeException('a real failure');
    }
}

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
        putenv('DB_DRIVER=json'); // no migrations needed
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        putenv('APP_KEY');
        putenv('APP_URL');
        putenv('DB_DRIVER');
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[RunInSeparateProcess]
    public function test_public_pages_are_stored_and_sign_in_pages_have_a_session(): void
    {
        foreach (['/', '/about'] as $path) {
            $miss = $this->get($path);
            $this->assertSame(200, $miss->getStatusCode(), $path . ': ' . substr((string) $miss->getBody(), 0, 300));
            $this->assertSame('miss', $miss->getHeaderLine('X-Page-Cache'), $path);
            $this->assertSame('', $miss->getHeaderLine('X-Page-Cache-Skip'), $path);
            $this->assertSame('hit', $this->get($path)->getHeaderLine('X-Page-Cache'), $path . ' is stored');
        }

        // The home page shows the error boundary demo as a preview: the boundary's own output.
        $home = (string) $this->get('/')->getBody();
        $this->assertStringContainsString('<div class="error-demo-fallback">', $home, 'ErrorDemo\'s fallback()');
        $this->assertStringContainsString('<div style="display:none;" data-component-error="true"></div>', $home, 'ErrorDemoRaw in production');

        // A component that really fails still keeps its page from being stored.
        file_put_contents($this->dir . '/app/pages/broken.twig', '{% extends "_layout.twig" %}{% block content %}{{ component(\'' . str_replace('\\', '\\\\', FailingComponent::class) . '\') }}{% endblock %}');
        $broken = $this->get('/broken');
        $this->assertSame(200, $broken->getStatusCode());
        $this->assertSame('refused:component-error', $broken->getHeaderLine('X-Page-Cache-Skip'));

        // The sign-in page keeps its session (its form, its flash messages), so it is never stored.
        $app = $this->app();
        $login = $app->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost:8080/login'));
        $this->assertSame(200, $login->getStatusCode());
        $this->assertTrue($app->container()->get(\Preflow\Core\Http\Session\SessionInterface::class)->isStarted());
        $this->assertNotSame('hit', $this->get('/login')->getHeaderLine('X-Page-Cache'));
    }

    #[RunInSeparateProcess]
    public function test_a_signed_in_visitor_sees_their_account_on_public_pages(): void
    {
        $app = $this->app();
        $user = new \App\Models\User();
        $user->fill(['uuid' => 'user-1', 'email' => 'ada@example.com', 'passwordHash' => 'x']);
        $app->container()->get(\Preflow\Data\DataManager::class)->save($user);
        $this->get('/about'); // stored for anonymous visitors
        $this->assertSame('hit', $this->get('/about')->getHeaderLine('X-Page-Cache'));

        // Signed in: a session holding the user, its cookie and the signed-in marker.
        session_name('preflow_session');
        session_id('skeletonsignedin0123456789');
        session_start();
        $_SESSION['_auth_user_id'] = 'user-1';
        session_write_close();
        $_COOKIE = ['preflow_session' => 'skeletonsignedin0123456789', 'preflow_auth' => '1'];

        $response = $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost:8080/about')->withCookieParams($_COOKIE));
        $body = (string) $response->getBody();
        $this->assertSame('bypass', $response->getHeaderLine('X-Page-Cache'));
        $this->assertStringContainsString('ada@example.com', $body, 'the public page shows the account');
        $this->assertStringContainsString('href="/admin"', $body);
        $this->assertStringNotContainsString('class="header-login-link"', $body);
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
