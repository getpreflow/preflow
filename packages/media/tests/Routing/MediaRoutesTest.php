<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Routing;

use PHPUnit\Framework\TestCase;
use Preflow\Media\MediaConfig;
use Preflow\Media\Routing\MediaRoutes;
use Preflow\Media\Tests\Support\MediaApp;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\Router;

/** The media core's own routes: the private-file route, the version-file route, then the variant route; added once per collection. */
final class MediaRoutesTest extends TestCase
{
    use MediaApp;

    protected function setUp(): void
    {
        $this->scaffoldMediaApp();
    }

    protected function tearDown(): void
    {
        $this->removeMediaApp();
    }

    public function test_routes_are_added_once(): void
    {
        $routes = new RouteCollection();
        $config = new MediaConfig(dir: '/tmp/media', url: '/media', privateUrl: '/tools/files');

        MediaRoutes::register($routes, $config);
        MediaRoutes::register($routes, $config);

        $this->assertSame([
            ['/tools/files/{id}', 'Preflow\\Media\\Http\\PrivateFileController@serve', 'GET', ['id'], '#^/tools/files/(?P<id>[a-f0-9]{32})$#', false, []],
            ['/tools/files/{id}/v/{n}', 'Preflow\\Media\\Http\\PrivateFileController@serveVersion', 'GET', ['id', 'n'], '#^/tools/files/(?P<id>[a-f0-9]{32})/v/(?P<n>[1-9][0-9]{0,5})$#', false, []],
            ['/media/{...path}', 'Preflow\\Media\\Http\\VariantController@serve', 'GET', ['path'], '#^/media/(?P<path>.+)$#', true, []],
        ], array_map(static fn ($e) => [$e->pattern, $e->handler, $e->method, $e->paramNames, $e->regex, $e->isCatchAll, $e->middleware], $routes->all()));
    }

    public function test_the_provider_adds_them_once_after_the_app_routes(): void
    {
        $router = $this->mediaApp()->container()->get(\Preflow\Core\Routing\RouterInterface::class);
        $this->assertInstanceOf(Router::class, $router);
        $handlers = array_map(static fn ($e) => $e->handler, $router->getCollection()->all());

        $this->assertSame(['Preflow\\Media\\Http\\PrivateFileController@serve', 'Preflow\\Media\\Http\\PrivateFileController@serveVersion', 'Preflow\\Media\\Http\\VariantController@serve'], $handlers);
    }

    public function test_core_alone_denies_private_files(): void
    {
        $f = new \Nyholm\Psr7\Factory\Psr17Factory();
        $pdf = "%PDF-1.4\n%%EOF\n";
        $asset = $this->media()->upload($f->createUploadedFile($f->createStream($pdf), strlen($pdf), UPLOAD_ERR_OK, 'Guide.pdf'), null, true);

        foreach ([$asset->id, str_repeat('0', 32)] as $id) {
            $res = $this->fetch('/files/' . $id);
            $this->assertSame(403, $res->getStatusCode(), $id);
            $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
            $this->assertStringNotContainsString('%PDF', (string) $res->getBody());
        }
    }
}
