<?php

declare(strict_types=1);

namespace Preflow\Examples\FolioDemo\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

/**
 * Smoke test for the shipped examples/folio-demo app: it must boot through the
 * real kernel and serve the Folio admin. This keeps the example from silently
 * rotting as the framework evolves. GET-only — it never writes records, so it is
 * independent of seed state.
 */
final class FolioDemoTest extends TestCase
{
    private function get(string $uri): ResponseInterface
    {
        $app = Application::create(dirname(__DIR__));
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_admin_redirects_to_login_when_unauthenticated(): void
    {
        $res = $this->get('/folio');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/login', $res->getHeaderLine('Location'));
    }

    /**
     * @runInSeparateProcess
     */
    public function test_login_page_renders(): void
    {
        $res = $this->get('/folio/login');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('name="email"', (string) $res->getBody());
    }

    /**
     * @runInSeparateProcess
     */
    public function test_stylesheet_route_serves_css(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
    }
}
