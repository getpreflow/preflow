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
}
