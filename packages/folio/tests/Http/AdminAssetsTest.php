<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Folio\Http\AdminAssetUrls;
use Preflow\Folio\Http\AssetController;

/** A package adds an admin asset file (e.g. a field type's stylesheet), served and versioned under {prefix}/_assets. */
final class AdminAssetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_admin_assets_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/pkg.css', '.pkg { color: red; }');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/pkg.css');
        @rmdir($this->dir);
    }

    public function test_added_asset_is_served_and_versioned(): void
    {
        $assets = new AdminAssets(['admin.css' => 'admin.css']);
        $controller = new AssetController(dirname(__DIR__, 2) . '/assets', $assets);
        $urls = new AdminAssetUrls('/folio', dirname(__DIR__, 2) . '/assets', $assets);
        $assets->add('pkg.css', $this->dir . '/pkg.css'); // after both were built, as a later provider's boot() does

        $res = $controller->serve((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/pkg.css')->withAttribute('file', 'pkg.css'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertStringContainsString('text/css', $res->getHeaderLine('Content-Type'));
        $this->assertSame('.pkg { color: red; }', (string) $res->getBody());
        $this->assertMatchesRegularExpression('#^/folio/_assets/pkg\.css\?v=[0-9a-f]{12}$#', $urls('pkg.css'));
        $this->assertStringContainsString('--c-accent', (string) $controller->serve((new Psr17Factory())->createServerRequest('GET', '/folio/_assets/admin.css')->withAttribute('file', 'admin.css'))->getBody());
    }

    public function test_taken_name_relative_path_or_odd_name_refused(): void
    {
        $assets = new AdminAssets(['admin.css' => 'admin.css']);
        foreach ([['admin.css', $this->dir . '/pkg.css'], ['pkg.css', 'pkg.css'], ['../pkg.css', $this->dir . '/pkg.css'], ['pkg.php', $this->dir . '/pkg.css']] as [$file, $path]) {
            try {
                $assets->add($file, $path);
                $this->fail("added {$file}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNull($assets->get('pkg.css'));
    }
}
