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

    public function test_busy_marker_is_added_beside_htmx_request_only_for_calls(): void
    {
        $collector = new AssetCollector(new NonceGenerator());
        $assets = new HtmxAssets($collector, 'on-demand');
        $assets->require();
        $plain = $collector->renderHead();
        $this->assertStringNotContainsString('preflow-busy', $plain, 'a page without a 0.86 call is as in 0.85');
        $this->assertMatchesRegularExpression('/<meta name="htmx-config" content=\'\{"inlineScriptNonce":"[^"]+","inlineStyleNonce":"[^"]+"\}\'>/', $plain, 'htmx keeps its own htmx-request');

        $collector = new AssetCollector(new NonceGenerator());
        $assets = new HtmxAssets($collector, 'on-demand');
        $assets->requireBusyMarker();
        $assets->requireBusyMarker();
        $head = $collector->renderHead();
        $this->assertTrue($assets->isRequired(), 'the marker needs htmx');
        $this->assertStringNotContainsString('preflowBusyMarker', $head, 'not in the head');
        $body = $collector->renderJsBody();
        $this->assertSame(1, substr_count($body, "preflowBusyMarker('" . \Preflow\Core\Http\Hypermedia\DrivesComponentActions::BUSY_CLASS . "')"));
        $this->assertStringContainsString('htmx:beforeSend', $body);
        $this->assertStringContainsString('htmx:afterRequest', $body);
        $this->assertStringContainsString('e.detail.elt', $body);
        $this->assertStringContainsString('window.preflowBusyMarkerOn', $body, 'set up once per page, also when an answer brings it again');
        $this->assertMatchesRegularExpression('/<meta name="htmx-config" content=\'\{"inlineScriptNonce":"[^"]+","inlineStyleNonce":"[^"]+"\}\'>/', $head);
        $this->assertStringContainsString('function preflowBusyMarker(', $body, 'a named function, not an IIFE (inline scripts are joined)');

        $never = new AssetCollector(new NonceGenerator());
        (new HtmxAssets($never, 'never'))->requireBusyMarker();
        $this->assertStringNotContainsString('preflowBusyMarker', $never->renderHead() . $never->renderJsBody());
    }

    public function test_disabler_is_added_once_after_htmx(): void
    {
        $collector = new AssetCollector(new NonceGenerator());
        $assets = new HtmxAssets($collector, 'on-demand');
        $assets->requireDisabler();
        $assets->requireDisabler();
        $this->assertTrue($assets->isRequired(), 'the disabler needs htmx');
        $this->assertStringNotContainsString('preflowDisabler', $collector->renderHead(), 'not in the head');
        $body = $collector->renderJsBody();
        $this->assertSame(1, substr_count($body, "preflowDisabler('data-preflow-disable');"));
        $this->assertStringContainsString('function preflowDisabler(', $body, 'a named function, not an IIFE (inline scripts are joined)');
        $this->assertStringContainsString('window.preflowDisablerOn', $body, 'set up once per page');
        $this->assertMatchesRegularExpression('#<script nonce="[^"]+">(?:(?!</script>).)*preflowDisabler#s', $body, 'under the page nonce like the busy marker');

        // Submit controls: a button whose type is submit (none, empty, invalid or submit), input submit or image; a form's own list (form="…" too).
        $this->assertStringContainsString("(c.tagName === 'BUTTON' && c.type === 'submit') || (c.tagName === 'INPUT' && (c.type === 'submit' || c.type === 'image'))", $body);
        $this->assertStringContainsString("el.tagName === 'FORM' ? Array.prototype.slice.call(el.elements)", $body);
        $this->assertStringContainsString("document.querySelectorAll('input[type=image]')", $body, 'image buttons are not in form.elements');
        // A region's fields.
        $this->assertStringContainsString("how.indexOf('fields ') === 0", $body);
        $this->assertStringContainsString("region.querySelectorAll('input, select, textarea, button')", $body);
        // Disabled when triggered (htmx:confirm comes before the hx-sync queue), released when sent and done or never sent.
        foreach (['htmx:confirm', 'queueMicrotask', 'htmx:beforeSend', 'htmx:afterRequest', 'htmx:targetError', 'e.defaultPrevented', 'x.readyState >= 1 && x.readyState <= 3'] as $piece) {
            $this->assertStringContainsString($piece, $body);
        }
        $this->assertStringContainsString("var early = m.how === 'submits';", $body, "only submit controls are disabled while a request waits (a disabled field sends no value)");
        $this->assertStringContainsString('if (c.disabled) { return; }', $body, 'a control disabled already is not taken over');

        $never = new AssetCollector(new NonceGenerator());
        (new HtmxAssets($never, 'never'))->requireDisabler();
        $this->assertStringNotContainsString('preflowDisabler', $never->renderHead() . $never->renderJsBody());
    }

    /** $render in a render frame of its own, as every page render runs (0.81). */
    private static function frame(\Closure $render): mixed
    {
        $site = new \Preflow\Core\Context\Site('https://example.com', ['en'], 'en');

        return \Preflow\Core\Context\Contexts::run(\Preflow\Core\Context\RenderContext::internal(\Preflow\Core\Context\Purpose::Rebuild, $site, new \Preflow\Core\Context\SystemClock(), '/'), $render);
    }

    public function test_each_render_from_the_registered_assets_gets_htmx_again(): void
    {
        // Pages rendered one after another in one process (verify's second render, rebuilds)
        // each have a frame of their own, starting from what boot registered.
        $collector = new AssetCollector(new NonceGenerator());
        $assets = new HtmxAssets($collector, 'on-demand');

        foreach ([1, 2] as $render) {
            $head = self::frame(function () use ($assets, $collector): string {
                $assets->require();
                $assets->require();

                return $collector->renderHead();
            });
            $this->assertSame(1, substr_count($head, '<meta name="htmx-config"'), "render {$render}");
            $this->assertStringContainsString('var htmx=', $head, "render {$render}");
        }
    }

    public function test_the_script_is_read_once_per_process(): void
    {
        $script = sys_get_temp_dir() . '/htmx_once_' . bin2hex(random_bytes(4)) . '.js';
        file_put_contents($script, 'var htmx="first read";');
        try {
            $collector = new AssetCollector(new NonceGenerator());
            $assets = new HtmxAssets($collector, 'on-demand', $script);
            self::frame(fn () => $assets->require());
            file_put_contents($script, 'var htmx="read again";');

            $head = self::frame(function () use ($collector, $script): string {
                (new HtmxAssets($collector, 'on-demand', $script))->require();

                return $collector->renderHead();
            });
            $this->assertStringContainsString('first read', $head, 'added to every render, read once');
        } finally {
            @unlink($script);
        }
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
