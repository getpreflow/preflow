<?php

declare(strict_types=1);

namespace Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Http\Session\SessionInterface;

final class FolioWalkingSkeletonTest extends TestCase
{
    private function makeApp(): Application
    {
        $app = Application::create(dirname(__DIR__));
        $app->boot();
        return $app;
    }

    protected function tearDown(): void
    {
        // Close any active PHP session so the next test's app boot can call
        // session_name() / session_set_cookie_params() without warnings.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Clean up any page records created during tests so re-runs are clean.
        $dataDir = dirname(__DIR__) . '/storage/data/page';
        if (is_dir($dataDir)) {
            foreach (glob($dataDir . '/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    public function test_admin_dashboard_uses_override(): void
    {
        $app = $this->makeApp();
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('Custom Folio Dashboard', (string) $res->getBody());
    }

    public function test_create_then_render_on_frontend(): void
    {
        $app = $this->makeApp();

        // Obtain the CSRF token from the session.
        // CsrfToken::generate() is idempotent: it stores a token on first call and
        // returns the same value on subsequent calls for the same session.
        $session = $app->container()->get(SessionInterface::class);
        $token = CsrfToken::generate($session)->getValue();

        $create = $app->handle(
            (new Psr17Factory())->createServerRequest('POST', '/folio/page')
                ->withParsedBody([
                    'title'       => 'About Us',
                    'slug'        => 'folio-about',
                    'body'        => '<p>Hi</p>',
                    'status'      => 'published',
                    '_csrf_token' => $token,
                ])
        );
        $this->assertSame(302, $create->getStatusCode());

        $page = $app->handle((new Psr17Factory())->createServerRequest('GET', '/folio-about'));
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('About Us', (string) $page->getBody());
    }

    public function test_unknown_slug_is_404(): void
    {
        $app = $this->makeApp();
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/no-such-page'));
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_existing_app_route_beats_folio_catch_all(): void
    {
        // The skeleton serves '/' from app/pages/index.twig; Folio must not shadow it.
        $app = $this->makeApp();
        $res = $app->handle((new Psr17Factory())->createServerRequest('GET', '/'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringNotContainsString('404', (string) $res->getBody());
    }
}
