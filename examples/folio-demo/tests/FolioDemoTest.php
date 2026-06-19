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

    public function test_dashboard_boots_and_renders_shell(): void
    {
        $res = $this->get('/folio');
        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('folio-shell', $body);
        $this->assertStringContainsString('Pages', $body);    // discovered content type
        $this->assertStringContainsString('Articles', $body);
    }

    public function test_stylesheet_route_serves_css(): void
    {
        $res = $this->get('/folio/_assets/admin.css');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString(':root', (string) $res->getBody());
    }
}
