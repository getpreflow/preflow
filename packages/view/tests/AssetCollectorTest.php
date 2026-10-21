<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;

final class AssetCollectorTest extends TestCase
{
    private function collector(?NonceGenerator $nonce = null, bool $isProd = false): AssetCollector
    {
        return new AssetCollector(
            nonceGenerator: $nonce ?? new NonceGenerator(),
            isProd: $isProd,
        );
    }

    public function test_keyed_head_tags_are_added_once(): void
    {
        $c = $this->collector();
        $c->addHeadTag('<link rel="preload" href="/a.avif">', 'preload:a');
        $c->addHeadTag('<link rel="preload" href="/a.avif">', 'preload:a');
        $c->addHeadTag('<meta name="x">');
        $c->addHeadTag('<meta name="x">'); // unkeyed tags keep today's behaviour

        $head = $c->renderHead();

        $this->assertSame(1, substr_count($head, 'rel="preload"'));
        $this->assertSame(2, substr_count($head, '<meta name="x">'));
    }

    public function test_add_css_and_render(): void
    {
        $c = $this->collector();
        $c->addCss('.foo { color: red; }');

        $output = $c->renderCss();

        $this->assertStringContainsString('.foo { color: red; }', $output);
        $this->assertStringContainsString('<style', $output);
    }

    public function test_css_deduplicated_by_hash(): void
    {
        $c = $this->collector();
        $c->addCss('.foo { color: red; }');
        $c->addCss('.foo { color: red; }'); // duplicate
        $c->addCss('.bar { color: blue; }');

        $output = $c->renderCss();

        $this->assertSame(1, substr_count($output, '.foo { color: red; }'));
        $this->assertStringContainsString('.bar { color: blue; }', $output);
    }

    public function test_css_deduplicated_by_explicit_key(): void
    {
        $c = $this->collector();
        $c->addCss('.v1 { color: red; }', 'my-component');
        $c->addCss('.v2 { color: blue; }', 'my-component'); // same key, ignored

        $output = $c->renderCss();

        $this->assertStringContainsString('.v1 { color: red; }', $output);
        $this->assertStringNotContainsString('.v2', $output);
    }

    public function test_add_js_defaults_to_body(): void
    {
        $c = $this->collector();
        $c->addJs('console.log("body");');

        $body = $c->renderJsBody();
        $head = $c->renderJsHead();

        $this->assertStringContainsString('console.log("body")', $body);
        $this->assertSame('', $head);
    }

    public function test_add_js_to_head(): void
    {
        $c = $this->collector();
        $c->addJs('window.CONFIG = {};', JsPosition::Head);

        $head = $c->renderJsHead();
        $body = $c->renderJsBody();

        $this->assertStringContainsString('window.CONFIG = {}', $head);
        $this->assertSame('', $body);
    }

    public function test_add_js_inline(): void
    {
        $c = $this->collector();
        $c->addJs('alert("inline");', JsPosition::Inline);

        $inline = $c->renderJsInline();
        $body = $c->renderJsBody();

        $this->assertStringContainsString('alert("inline")', $inline);
        $this->assertSame('', $body);
    }

    public function test_js_deduplicated_by_hash(): void
    {
        $c = $this->collector();
        $c->addJs('console.log("x");');
        $c->addJs('console.log("x");'); // duplicate

        $output = $c->renderJsBody();

        $this->assertSame(1, substr_count($output, 'console.log("x")'));
    }

    public function test_nonce_included_in_style_tag(): void
    {
        $nonce = new NonceGenerator();
        $c = $this->collector($nonce);
        $c->addCss('.x {}');

        $output = $c->renderCss();

        $this->assertStringContainsString('nonce="' . $nonce->get() . '"', $output);
    }

    public function test_nonce_included_in_script_tag(): void
    {
        $nonce = new NonceGenerator();
        $c = $this->collector($nonce);
        $c->addJs('var x;');

        $output = $c->renderJsBody();

        $this->assertStringContainsString('nonce="' . $nonce->get() . '"', $output);
    }

    public function test_empty_collector_renders_nothing(): void
    {
        $c = $this->collector();

        $this->assertSame('', $c->renderCss());
        $this->assertSame('', $c->renderJsHead());
        $this->assertSame('', $c->renderJsBody());
        $this->assertSame('', $c->renderJsInline());
    }

    public function test_render_head_includes_css_and_head_js(): void
    {
        $c = $this->collector();
        $c->addCss('.page { margin: 0; }');
        $c->addJs('headScript();', JsPosition::Head);
        $c->addJs('bodyScript();', JsPosition::Body);

        $head = $c->renderHead();

        $this->assertStringContainsString('.page { margin: 0; }', $head);
        $this->assertStringContainsString('headScript()', $head);
        $this->assertStringNotContainsString('bodyScript()', $head);
    }

    public function test_render_assets_includes_body_js_only(): void
    {
        $c = $this->collector();
        $c->addCss('.page { margin: 0; }');
        $c->addJs('init();', JsPosition::Body);
        $c->addJs('headStuff();', JsPosition::Head);

        $assets = $c->renderAssets();

        $this->assertStringContainsString('init()', $assets);
        $this->assertStringNotContainsString('.page { margin: 0; }', $assets);
        $this->assertStringNotContainsString('headStuff()', $assets);
    }

    public function test_head_tags_included_in_render_head(): void
    {
        $c = $this->collector();
        $c->addHeadTag('<script src="https://cdn.example.com/lib.js" defer></script>');

        $head = $c->renderHead();

        $this->assertStringContainsString('https://cdn.example.com/lib.js', $head);
    }

    public function test_fork_creates_isolated_collector(): void
    {
        $nonce = new NonceGenerator();
        $original = new AssetCollector($nonce, isProd: false);
        $original->addCss('.original { color: red; }');

        $forked = $original->fork();
        $forked->addCss('.forked { color: blue; }');

        // Original should NOT have forked CSS
        $this->assertStringContainsString('.original', $original->renderCss());
        $this->assertStringNotContainsString('.forked', $original->renderCss());

        // Forked should NOT have original CSS
        $this->assertStringContainsString('.forked', $forked->renderCss());
        $this->assertStringNotContainsString('.original', $forked->renderCss());

        // Both share the same nonce
        $this->assertSame($original->getNonce(), $forked->getNonce());
    }

    public function test_inject_skips_what_the_template_already_rendered(): void
    {
        $assets = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
        $assets->addCss('.a{color:red}');
        $assets->addJs('window.x=1;');
        $body = $assets->renderAssets(); // the layout called {{ assets() }}

        $html = $assets->injectInto('<html><head></head><body>' . $body . '</body></html>');

        $this->assertSame(1, substr_count($html, 'window.x=1;'));
        $this->assertSame(1, substr_count($html, '.a{color:red}'));
    }

    public function test_assets_collected_after_an_early_head_call_are_still_injected(): void
    {
        $assets = $this->collector();
        $assets->addCss('.early{color:red}');
        $head = $assets->renderHead(); // the layout's {{ head() }} runs before the page's CSS is collected
        $assets->addCss('.late{color:blue}');
        $assets->addJs('window.late=1;', JsPosition::Head);
        $assets->addHeadTag('<link rel="preload" href="/a.avif">', 'a');

        $html = $assets->injectInto('<html><head>' . $head . '</head><body></body></html>');

        $this->assertSame(1, substr_count($html, '.early{color:red}'));
        $this->assertSame(1, substr_count($html, '.late{color:blue}'));
        $this->assertSame(1, substr_count($html, 'window.late=1;'));
        $this->assertSame(1, substr_count($html, 'href="/a.avif"'));
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, '.late{color:blue}'));
    }

    public function test_body_js_collected_after_assets_call_is_still_injected(): void
    {
        $assets = $this->collector();
        $body = $assets->renderAssets();
        $assets->addJs('window.after=1;');

        $html = $assets->injectInto('<html><head></head><body>' . $body . '</body></html>');

        $this->assertSame(1, substr_count($html, 'window.after=1;'));
    }
}
