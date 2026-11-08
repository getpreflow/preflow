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
        // Clean globals and environment, as a new process on the server has: nothing from tests
        // that ran before this one in the suite.
        unset($_SESSION);
        $_COOKIE = $_GET = $_POST = $_REQUEST = $_FILES = [];
        foreach (['APP_NAME', 'APP_TIMEZONE', 'APP_LOCALE', 'APP_ENGINE', 'APP_SECURE', 'DB_PATH'] as $name) {
            putenv($name);
        }
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

    #[RunInSeparateProcess]
    public function test_the_login_and_logout_forms_post_their_csrf_token(): void
    {
        $user = new \App\Models\User();
        $user->fill(['uuid' => 'user-1', 'email' => 'ada@example.com', 'passwordHash' => (new \Preflow\Auth\NativePasswordHasher())->hash('correct horse')]);
        $this->app()->container()->get(\Preflow\Data\DataManager::class)->save($user);

        // The login form: its session (a session path) and the token in its field.
        $form = (string) $this->get('/login')->getBody();
        $_COOKIE = ['preflow_session' => session_id()];
        $login = $this->post('/login', ['email' => 'ada@example.com', 'password' => 'correct horse', '_csrf_token' => $this->field($form)]);
        $this->assertSame(302, $login->getStatusCode(), (string) $login->getBody());
        $this->assertSame('/', $login->getHeaderLine('Location'), 'signed in');
        $_COOKIE = ['preflow_session' => session_id(), 'preflow_auth' => '1'];

        // The logout form on a public page (the session resumed by its cookie).
        $page = (string) $this->get('/about', $_COOKIE)->getBody();
        $this->assertStringContainsString('ada@example.com', $page);
        $logout = $this->post('/logout', ['_csrf_token' => $this->field($page)]);
        $this->assertSame(302, $logout->getStatusCode(), (string) $logout->getBody());
        $this->assertSame('/login', $logout->getHeaderLine('Location'));

        // Without the token the forms are refused, as a forged post would be.
        $this->assertSame(403, $this->post('/logout', [])->getStatusCode());
    }

    /** The CSRF field's value in a page's form. */
    private function field(string $html): string
    {
        $this->assertSame(1, preg_match('/<input type="hidden" name="_csrf_token" value="([^"]+)"/', $html, $m), 'a CSRF field');

        return html_entity_decode($m[1]);
    }

    /** @param array<string, string> $body */
    private function post(string $path, array $body): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', 'http://localhost:8080' . $path)
            ->withCookieParams($_COOKIE)->withParsedBody($body));
    }

    /** The app as the web serves it, each page it stores verified (preflow/testing). */
    private function app(): Application
    {
        return \Preflow\Testing\WebApplication::boot($this->dir);
    }

    /** @param array<string, string> $cookies */
    private function get(string $path, array $cookies = []): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', 'http://localhost:8080' . $path)->withCookieParams($cookies));
    }
}
