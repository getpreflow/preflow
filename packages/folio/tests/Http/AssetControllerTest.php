<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AssetController;

final class AssetControllerTest extends TestCase
{
    public function test_serves_existing_css_with_type_and_cache_headers(): void
    {
        $path = dirname(__DIR__, 2) . '/assets/admin.css';
        $controller = new AssetController($path);

        $res = $controller->adminCss((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('max-age', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }

    public function test_missing_file_returns_404(): void
    {
        $controller = new AssetController('/no/such/admin.css');
        $res = $controller->adminCss((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css'));
        $this->assertSame(404, $res->getStatusCode());
    }
}
