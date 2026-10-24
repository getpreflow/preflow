<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Extension\ExtensionAssets;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\Screen\PageAssets;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Folio\Http\AdminAssetUrls;
use Preflow\Folio\Tests\Support\ExtensionAssetsSite;

final class PageAssetsTest extends TestCase
{
    use ExtensionAssetsSite;

    private PageAssets $pages;

    protected function setUp(): void
    {
        $folio = new AdminAssets([
            'htmx.js' => 'htmx.js',
            'trix.css' => 'trix.css',
            'trix.js' => 'trix.js',
        ]);
        $this->pages = new PageAssets(
            new ExtensionAssets($this->assetsSite(), '/folio'),
            $folio,
            new AdminAssetUrls('/folio', $this->site, $folio),
        );
        $this->siteWrite('shop/assets/mod.mjs', 'export {};');
        $this->siteWrite('shop/assets/font.woff2', 'x');
        $this->siteWrite('htmx.js', 'htmx');
        $this->siteWrite('trix.css', 'trix css');
        $this->siteWrite('trix.js', 'trix js');
    }

    protected function tearDown(): void
    {
        $this->removeAssetsSite();
    }

    public function test_own_files_become_versioned_urls_by_type(): void
    {
        $r = $this->pages->resolve('shop', ['shop.css', 'shop.js', 'mod.mjs'], 'Screen X::index');

        $this->assertMatchesRegularExpression('#^/folio/_assets/x/shop/shop\.css\?v=[0-9a-f]{12}$#', $r['styles'][0]);
        $this->assertMatchesRegularExpression('#^/folio/_assets/x/shop/shop\.js\?v=#', $r['scripts'][0]);
        $this->assertMatchesRegularExpression('#^/folio/_assets/x/shop/mod\.mjs\?v=#', $r['modules'][0]);
        $this->assertFalse($r['htmx']);
    }

    public function test_folio_htmx_sets_the_htmx_flag(): void
    {
        $r = $this->pages->resolve('shop', ['@folio/htmx.js'], 'Screen X::index');

        $this->assertTrue($r['htmx']);
        $this->assertMatchesRegularExpression('#^/folio/_assets/htmx\.js\?v=[0-9a-f]{12}$#', $r['scripts'][0]);
    }

    public function test_folio_trix_is_one_style_and_one_script(): void
    {
        $r = $this->pages->resolve('shop', ['@folio/trix'], 'Screen X::index');

        $this->assertCount(1, $r['styles']);
        $this->assertCount(1, $r['scripts']);
        $this->assertStringContainsString('/folio/_assets/trix.css?v=', $r['styles'][0]);
        $this->assertStringContainsString('/folio/_assets/trix.js?v=', $r['scripts'][0]);
        $this->assertFalse($r['htmx']);
    }

    public function test_another_extensions_file(): void
    {
        $r = $this->pages->resolve('other', ['@shop/vendor/lib/lib.js'], 'Screen X::index');

        $this->assertStringStartsWith('/folio/_assets/x/shop/vendor/lib/lib.js?v=', $r['scripts'][0]);
    }

    public function test_order_is_kept_and_duplicates_dropped(): void
    {
        $r = $this->pages->resolve('shop', ['shop.js', '@shop/shop.js', 'vendor/lib/lib.js', 'shop.js'], 'Screen X::index');

        $this->assertCount(2, $r['scripts']);
        $this->assertStringContainsString('/shop.js?', $r['scripts'][0]);
        $this->assertStringContainsString('/lib.js?', $r['scripts'][1]);
    }

    public function test_other_types_are_not_printed(): void
    {
        $r = $this->pages->resolve('shop', ['font.woff2'], 'Screen X::index');

        $this->assertSame(['styles' => [], 'scripts' => [], 'modules' => [], 'htmx' => false], $r);
    }

    #[DataProvider('bad')]
    public function test_bad_entries_throw_naming_the_owner(string $entry): void
    {
        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Screen Acme\Orders::index');

        $this->pages->resolve('shop', [$entry], 'Screen Acme\Orders::index');
    }

    /** @return array<string, array{string}> */
    public static function bad(): array
    {
        return [
            'unknown folio name' => ['@folio/nope'],
            'missing own file' => ['gone.css'],
            'missing other file' => ['@shop/gone.css'],
            'disabled extension' => ['@off/off.css'],
            'no path' => ['@shop'],
            'empty' => [''],
            'traversal' => ['../secret.css'],
        ];
    }

    public function test_message_names_the_entry(): void
    {
        try {
            $this->pages->resolve('shop', ['gone.css'], 'Form panel A::b');
            $this->fail('expected exception');
        } catch (ExtensionException $e) {
            $this->assertStringContainsString('"gone.css"', $e->getMessage());
            $this->assertStringContainsString('"shop"', $e->getMessage());
        }
    }
}
