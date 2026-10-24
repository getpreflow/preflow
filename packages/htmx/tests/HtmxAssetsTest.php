<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmxAssets;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

final class HtmxAssetsTest extends TestCase
{
    public function test_require_inlines_script_once_with_nonce(): void
    {
        $collector = new AssetCollector(new NonceGenerator());
        $nonce = $collector->getNonce();
        $assets = new HtmxAssets($collector, 'on-demand');

        $assets->require();
        $assets->require();
        $head = $collector->renderHead();

        $this->assertTrue($assets->isRequired());
        $this->assertSame(1, substr_count($head, '<script nonce="' . $nonce . '">'));
        // 'htmx-config' also occurs inside the htmx source, so count the meta tag itself.
        $this->assertSame(1, substr_count($head, '<meta name="htmx-config"'));
        $this->assertStringContainsString('"inlineScriptNonce":"' . $nonce . '"', $head);
        $this->assertStringContainsString('"inlineStyleNonce":"' . $nonce . '"', $head);
        $this->assertStringContainsString('htmx', $head);
        $this->assertStringNotContainsString('unpkg.com', $head);
    }

    public function test_swapped_scripts_are_only_trusted_with_the_fragment_nonce(): void
    {
        $collector = new AssetCollector(new NonceGenerator());
        (new HtmxAssets($collector, 'always'))->require();
        $head = $collector->renderHead();

        // Guard registered before htmx re-stamps swapped <script>/<style> with the page nonce.
        $this->assertStringContainsString('htmx:beforeSwap', $head);
        $this->assertStringContainsString('HX-Nonce', $head);
    }

    public function test_surviving_swapped_styles_get_the_page_nonce(): void
    {
        $collector = new AssetCollector(new NonceGenerator());
        (new HtmxAssets($collector, 'always'))->require();
        $head = $collector->renderHead();

        // htmx re-stamps swapped scripts itself, but not styles: the guard does it for
        // the tags that carried the response's nonce.
        $this->assertStringContainsString('htmx.config.inlineStyleNonce', $head);
    }

    public function test_never_mode_adds_nothing(): void
    {
        $collector = new AssetCollector(new NonceGenerator());
        $assets = new HtmxAssets($collector, 'never');

        $assets->require();

        $this->assertFalse($assets->isRequired());
        $this->assertSame('', $collector->renderHead());
    }

    public function test_invalid_mode_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('config/htmx.php');
        new HtmxAssets(new AssetCollector(new NonceGenerator()), 'sometimes');
    }
}
