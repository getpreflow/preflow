<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AssetController;

final class AssetControllerTest extends TestCase
{
    private function controller(): AssetController
    {
        // baseDir = packages/folio/assets ; admin.css exists there.
        return new AssetController(dirname(__DIR__, 2) . '/assets', ['admin.css' => 'admin.css']);
    }

    private function get(AssetController $c, string $file)
    {
        return $c->serve((new Psr17Factory())
            ->createServerRequest('GET', '/folio/_assets/' . $file)
            ->withAttribute('file', $file));
    }

    public function test_serves_allowlisted_css_with_type_and_cache(): void
    {
        $res = $this->get($this->controller(), 'admin.css');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('max-age', $res->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('--c-accent', (string) $res->getBody());
    }

    public function test_unknown_file_404(): void
    {
        $res = $this->get($this->controller(), 'secrets.env');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_allowlisted_but_missing_file_404(): void
    {
        $c = new AssetController('/no/such/dir', ['admin.css' => 'admin.css']);
        $res = $this->get($c, 'admin.css');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_js_content_type(): void
    {
        // map a js file that exists (use admin.css contents via a .js alias is not valid;
        // instead assert extension-based type using a temp dir)
        $dir = sys_get_temp_dir() . '/folio_assets_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/x.js', '/* js */');
        $c = new AssetController($dir, ['x.js' => 'x.js']);
        $res = $this->get($c, 'x.js');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/javascript', $res->getHeaderLine('Content-Type'));
        @unlink($dir . '/x.js');
        @rmdir($dir);
    }
}
