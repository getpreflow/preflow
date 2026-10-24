<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Extension\ExtensionAssets;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\ExtensionAssetsSite;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\View\TemplateEngineInterface;

final class ExtensionAssetsTest extends TestCase
{
    use ExtensionAssetsSite;
    use PublishingScaffold;

    private const SHOP = 'Preflow\\Folio\\Tests\\Fixtures\\extensions\\Shop\\Extension';

    private ExtensionAssets $assets;

    protected function setUp(): void
    {
        $this->assets = new ExtensionAssets($this->assetsSite(), '/folio');
    }

    protected function tearDown(): void
    {
        $this->removeAssetsSite();
        $this->removeScaffold();
    }

    public function test_path_is_the_real_path_of_a_file_in_assets(): void
    {
        $this->assertSame(realpath($this->site . '/shop/assets/shop.css'), $this->assets->path('shop', 'shop.css'));
        $this->assertSame(realpath($this->site . '/shop/assets/vendor/lib/lib.js'), $this->assets->path('shop', 'vendor/lib/lib.js'));
    }

    #[DataProvider('refused')]
    public function test_path_is_null_for_refused_requests(string $extension, string $path): void
    {
        $this->assertNull($this->assets->path($extension, $path));
    }

    public function test_base_is_the_extension_asset_folder_url(): void
    {
        $this->assertSame('/folio/_assets/x/shop/', $this->assets->base('shop'));
        $this->assertSame('/cms/_assets/x/shop/', (new ExtensionAssets($this->assetsSite(), '/cms/'))->base('shop'));
    }

    public function test_url_is_versioned_by_content_hash(): void
    {
        $url = $this->assets->url('shop', 'vendor/lib/lib.js');

        $this->assertMatchesRegularExpression('#^/folio/_assets/x/shop/vendor/lib/lib\.js\?v=[0-9a-f]{12}$#', $url);
        $this->assertSame(substr(hash_file('xxh3', $this->site . '/shop/assets/vendor/lib/lib.js'), 0, 12), substr($url, -12));
    }

    public function test_url_changes_when_the_file_changes(): void
    {
        $before = $this->assets->url('shop', 'shop.css');
        $this->siteWrite('shop/assets/shop.css', '.shop-orders { display: flex; }');

        $after = $this->assets->url('shop', 'shop.css');

        $this->assertNotSame($before, $after);
        $this->assertStringStartsWith('/folio/_assets/x/shop/shop.css?v=', $after);
    }

    public function test_url_of_a_file_that_is_not_served_throws(): void
    {
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"nope.css"');

        $this->assets->url('shop', 'nope.css');
    }

    public function test_url_of_a_disabled_extension_throws(): void
    {
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"off"');

        $this->assets->url('off', 'off.css');
    }

    public function test_twig_function_and_route_serve_an_enabled_extensions_asset(): void
    {
        $this->scaffoldPublishing();
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/shop', 'extra' => ['folio' => ['extension' => self::SHOP]]],
        ]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"shop": {"disabled_parts": ["screen:orders"]}}}');
        $this->write('resources/folio/asset-probe.twig', "{{ folio_extension_asset('shop', 'shop.css') }}");
        $app = $this->app();

        $html = $app->container()->get(TemplateEngineInterface::class)->render('@folio/asset-probe.twig');

        $file = dirname(__DIR__) . '/Fixtures/extensions/Shop/assets/shop.css';
        $this->assertSame('/folio/_assets/x/shop/shop.css?v=' . substr(hash_file('xxh3', $file), 0, 12), $html);

        $res = $this->get('/folio/_assets/x/shop/vendor/lib/lib.js');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/javascript; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame("window.shopLib = { version: 1 };\n", (string) $res->getBody());
        $this->assertSame(404, $this->get('/folio/_assets/x/shop/../Extension.php')->getStatusCode());
    }
}
