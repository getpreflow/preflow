<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Extension\ExtensionAssets;
use Preflow\Folio\Http\ExtensionAssetController;
use Preflow\Folio\Tests\Support\ExtensionAssetsSite;
use Psr\Http\Message\ResponseInterface;

final class ExtensionAssetControllerTest extends TestCase
{
    use ExtensionAssetsSite;

    private ExtensionAssetController $controller;

    protected function setUp(): void
    {
        $this->controller = new ExtensionAssetController(new ExtensionAssets($this->assetsSite(), '/folio'));
    }

    protected function tearDown(): void
    {
        $this->removeAssetsSite();
    }

    /** @param array<string, string> $query @param array<string, string> $headers */
    private function get(string $extension, string $path, array $query = [], array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory())
            ->createServerRequest('GET', '/folio/_assets/x/' . $extension . '/' . $path)
            ->withQueryParams($query)
            ->withAttribute('extension', $extension)
            ->withAttribute('path', $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->controller->serve($request);
    }

    private function hash(string $extension, string $path): string
    {
        $real = (new ExtensionAssets($this->assetsSite(), '/folio'))->path($extension, $path);

        return substr(hash_file('xxh3', $real), 0, 12);
    }

    public function test_serves_a_root_file_with_type_nosniff_and_immutable_cache(): void
    {
        $res = $this->get('shop', 'shop.css', ['v' => $this->hash('shop', 'shop.css')]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/css; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('public, max-age=31536000, immutable', $res->getHeaderLine('Cache-Control'));
        $this->assertSame(".shop-orders { display: grid; }\n", (string) $res->getBody());
    }

    public function test_serves_a_file_in_a_subfolder(): void
    {
        $res = $this->get('shop', 'vendor/lib/lib.js', ['v' => $this->hash('shop', 'vendor/lib/lib.js')]);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/javascript; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('public, max-age=31536000, immutable', $res->getHeaderLine('Cache-Control'));
        $this->assertSame("window.shopLib = { version: 1 };\n", (string) $res->getBody());
    }

    public function test_unversioned_request_revalidates_with_an_etag(): void
    {
        $res = $this->get('shop', 'shop.css');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('no-cache', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('"' . $this->hash('shop', 'shop.css') . '"', $res->getHeaderLine('ETag'));
    }

    public function test_stale_version_is_not_cached_immutably(): void
    {
        $res = $this->get('shop', 'shop.css', ['v' => 'abcdef123456']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('no-cache', $res->getHeaderLine('Cache-Control'));
        $this->assertSame('"' . $this->hash('shop', 'shop.css') . '"', $res->getHeaderLine('ETag'));
    }

    public function test_matching_if_none_match_answers_304_without_a_body(): void
    {
        $etag = '"' . $this->hash('shop', 'shop.css') . '"';

        $res = $this->get('shop', 'shop.css', [], ['If-None-Match' => $etag]);

        $this->assertSame(304, $res->getStatusCode());
        $this->assertSame('', (string) $res->getBody());
        $this->assertSame('no-cache', $res->getHeaderLine('Cache-Control'));
        $this->assertSame($etag, $res->getHeaderLine('ETag'));
    }

    public function test_mismatching_if_none_match_serves_the_file(): void
    {
        $res = $this->get('shop', 'shop.css', [], ['If-None-Match' => '"000000000000"']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(".shop-orders { display: grid; }\n", (string) $res->getBody());
    }

    /** @return array<string, array{string, string}> */
    public static function types(): array
    {
        return [
            'css' => ['a.css', 'text/css; charset=UTF-8'],
            'js' => ['a.js', 'text/javascript; charset=UTF-8'],
            'mjs' => ['a.mjs', 'text/javascript; charset=UTF-8'],
            'map' => ['a.js.map', 'application/json; charset=UTF-8'],
            'json' => ['a.json', 'application/json; charset=UTF-8'],
            'svg' => ['a.svg', 'image/svg+xml'],
            'png' => ['a.png', 'image/png'],
            'webp' => ['a.webp', 'image/webp'],
            'woff2' => ['a.woff2', 'font/woff2'],
        ];
    }

    #[DataProvider('types')]
    public function test_content_type_per_allowed_type(string $file, string $type): void
    {
        $this->siteWrite('shop/assets/types/' . $file, 'x');

        $res = $this->get('shop', 'types/' . $file);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($type, $res->getHeaderLine('Content-Type'));
    }

    public function test_other_types_are_not_served(): void
    {
        foreach (['a.html', 'a.txt', 'a.phtml', 'a.htm', 'a.xml', 'a'] as $file) {
            $this->siteWrite('shop/assets/' . $file, 'x');
            $this->assertSame(404, $this->get('shop', $file)->getStatusCode(), $file);
        }
    }

    #[DataProvider('refused')]
    public function test_refused_requests_404_without_file_contents(string $extension, string $path): void
    {
        $res = $this->get($extension, $path);

        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringNotContainsString('TOP-SECRET', (string) $res->getBody());
        $this->assertStringNotContainsString('.off', (string) $res->getBody());
        $this->assertStringNotContainsString('shop-orders', (string) $res->getBody());
    }
}
