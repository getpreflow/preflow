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

    public function test_isolate_renders_with_empty_registries_and_restores_the_request_state(): void
    {
        $collector = $this->collector();
        $collector->addCss('.outer { color: blue; }');
        $collector->addHeadTag('<meta name="outer">', 'outer');
        $collector->renderHead();

        $inner = $collector->isolate(function () use ($collector): string {
            $collector->addCss('.inner { color: red; }');

            return $collector->renderHead();
        });

        $this->assertStringContainsString('.inner', $inner);
        $this->assertStringNotContainsString('.outer', $inner);
        $this->assertStringNotContainsString('name="outer"', $inner);
        $this->assertStringContainsString('.outer', $collector->renderCss());
        $this->assertStringNotContainsString('.inner', $collector->renderCss());
        $this->assertSame('', $collector->renderHead(), 'what was printed before stays printed');
    }

    public function test_isolate_restores_after_an_exception(): void
    {
        $collector = $this->collector();
        $collector->addCss('.outer { color: blue; }');

        try {
            $collector->isolate(function () use ($collector): void {
                $collector->addCss('.inner { color: red; }');
                throw new \RuntimeException('render failed');
            });
        } catch (\RuntimeException) {
        }

        $this->assertStringContainsString('.outer', $collector->renderCss());
        $this->assertStringNotContainsString('.inner', $collector->renderCss());
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

    public function test_inline_js_is_printed_on_full_pages_once(): void
    {
        $assets = $this->collector();
        $assets->addJs('window.body=1;');
        $assets->addJs('window.inline=1;', JsPosition::Inline);

        // A layout's {{ assets() }} prints it with the body JS…
        $body = $assets->renderAssets();
        $this->assertStringContainsString('window.inline=1;', $body);
        $this->assertLessThan(strpos($body, 'window.inline=1;'), strpos($body, 'window.body=1;'));

        // …and injectInto() adds what no template printed, once.
        $assets->addJs('window.late=1;', JsPosition::Inline);
        $html = $assets->injectInto('<html><head></head><body>' . $body . '</body></html>');
        $this->assertSame(1, substr_count($html, 'window.inline=1;'));
        $this->assertSame(1, substr_count($html, 'window.late=1;'));
        $this->assertLessThan(strpos($html, '</body>'), strpos($html, 'window.late=1;'));
    }

    public function test_scoped_css_keeps_keyframes_and_font_faces_outside_the_scope(): void
    {
        $assets = $this->collector();
        $assets->setCssScope('card-1a2b3c');
        $assets->addCss(<<<'CSS'
            .spin { animation: spin 1s linear infinite; content: "}"; }
            @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
            @-webkit-keyframes pulse { 50% { opacity: .5; } }
            /* a { brace } in a comment */
            @font-face { font-family: "Card"; src: url("/card.woff2") format("woff2"); }
            @media (min-width: 40em) { .spin { width: 2rem; } }
            @supports (display: grid) { .grid { display: grid; } }
            CSS);
        $assets->setCssScope(null);
        $css = $assets->renderCss();

        // At-rules that can't be nested stand on their own, before the scoped block…
        $scopeAt = strpos($css, '.card-1a2b3c {');
        $this->assertNotFalse($scopeAt);
        foreach (['@keyframes spin { from', '@-webkit-keyframes pulse', '@font-face { font-family: "Card"'] as $rule) {
            $at = strpos($css, $rule);
            $this->assertNotFalse($at, $rule);
            $this->assertLessThan($scopeAt, $at, $rule);
        }
        $this->assertSame(1, substr_count($css, '@keyframes spin'));
        // …the rest stays scoped, @media and @supports with their inner rules.
        $scoped = substr($css, $scopeAt);
        $this->assertStringContainsString('.spin { animation: spin 1s linear infinite; content: "}"; }', $scoped);
        $this->assertStringContainsString('@media (min-width: 40em) { .spin { width: 2rem; } }', $scoped);
        $this->assertStringContainsString('@supports (display: grid) { .grid { display: grid; } }', $scoped);
        $this->assertStringNotContainsString('@keyframes', $scoped);
        $this->assertStringNotContainsString('@font-face', $scoped);

        // Without a scope nothing moves.
        $plain = $this->collector();
        $plain->addCss('@keyframes x { to { opacity: 0; } } .a { animation: x 1s; }');
        $this->assertStringContainsString('@keyframes x { to { opacity: 0; } } .a { animation: x 1s; }', $plain->renderCss());
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/preflow-assets-' . bin2hex(random_bytes(4));
        $this->dirs[] = $dir;
        return $dir;
    }

    /** @var string[] */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (glob($dir . '/assets/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir . '/assets');
            @rmdir($dir);
        }
    }

    private function prodWithCache(string $dir): AssetCollector
    {
        return new AssetCollector(new NonceGenerator(), isProd: true, cachePath: $dir);
    }

    private function renderCss(AssetCollector $c, string $css): string
    {
        $c->addCss($css);
        return $c->renderHead();
    }

    public function test_minified_css_is_reused_for_the_same_input(): void
    {
        $dir = $this->cacheDir();
        $css = ".a {\n  color: red;\n}\n";
        $this->renderCss($this->prodWithCache($dir), $css);

        $files = glob($dir . '/assets/*.php');
        $this->assertCount(1, $files);
        file_put_contents($files[0], "<?php return '.cached{}';\n");

        $this->assertStringContainsString('.cached{}', $this->renderCss($this->prodWithCache($dir), $css));
    }

    public function test_cached_output_equals_fresh_output(): void
    {
        $dir = $this->cacheDir();
        $css = ".a {\n  color: red;\n}\n.b { margin: 0 0 0 0; }";
        $n = new NonceGenerator();
        $mk = static fn (?string $d) => new AssetCollector($n, isProd: true, cachePath: $d);
        $fresh = $this->renderCss($mk($dir), $css);
        $cached = $this->renderCss($mk($dir), $css);
        $plain = $this->renderCss($mk(null), $css);

        $this->assertSame($fresh, $cached);
        $this->assertSame($plain, $cached);
    }

    public function test_different_input_gets_its_own_entry(): void
    {
        $dir = $this->cacheDir();
        $this->renderCss($this->prodWithCache($dir), '.a { color: red; }');
        $this->renderCss($this->prodWithCache($dir), '.a { color: blue; }');
        $this->assertCount(2, glob($dir . '/assets/*.php'));
    }

    public function test_css_and_js_with_the_same_text_do_not_share_an_entry(): void
    {
        $dir = $this->cacheDir();
        $c = $this->prodWithCache($dir);
        $c->addCss('a { b: c }');
        $c->addJs('a { b: c }', \Preflow\View\JsPosition::Body);
        $c->renderHead();
        $c->renderAssets();
        $this->assertCount(2, glob($dir . '/assets/*.php'));
    }

    public function test_without_a_cache_path_it_still_minifies(): void
    {
        $out = $this->renderCss($this->collector(isProd: true), ".a {\n  color: red;\n}\n");
        $this->assertStringContainsString('.a{color:red}', $out);
    }

    public function test_per_entry_output_equals_whole_block_minify(): void
    {
        $css = ['a' => ".a {\n  color: red;\n}\n", 'b' => ".b > .c { margin: 0 0 0 0; }\n@media (min-width:600px){ .d{ padding:1px } }", 'c' => '/* x */ .e{background:url(a.png)}'];
        $js = ['a' => "var x = 1;\nfunction f() { return x }\n", 'b' => "document.title = 'a';\n", 'c' => "(function(){ f() })();"];
        $c = new AssetCollector(new NonceGenerator(), isProd: true);
        foreach ($css as $k => $v) {
            $c->addCss($v, $k);
        }
        foreach ($js as $k => $v) {
            $c->addJs($v, \Preflow\View\JsPosition::Head, $k);
        }
        $out = $c->renderHead();
        $whole = (new \MatthiasMullie\Minify\CSS(implode("\n", $css)))->minify();
        $this->assertStringContainsString('>' . $whole . '</style>', $out);
        $wholeJs = (new \MatthiasMullie\Minify\JS(implode("\n", $js)))->minify();
        // JS differs from the whole-block result only by the ";\n" put between entries: the minifier
        // strips each entry's trailing semicolon, and without it `a\n(function(){})()` would be a call.
        preg_match('#<script[^>]*>([^<]*)</script>#', $out, $m);
        $this->assertSame(
            str_replace([';', "\n"], '', $wholeJs),
            str_replace([';', "\n"], '', $m[1]),
        );
        $this->assertStringContainsString("'a';\n(function", $m[1]);
    }

    public function test_a_garbage_cache_file_falls_back_to_a_fresh_minify(): void
    {
        $dir = $this->cacheDir();
        $css = '.a { color: red; }';
        $this->renderCss($this->prodWithCache($dir), $css);
        $file = glob($dir . '/assets/*.php')[0];
        file_put_contents($file, "<?php return '.a{col");
        $this->assertStringContainsString('.a{color:red}', $this->renderCss($this->prodWithCache($dir), $css));
        file_put_contents($file, '<?php throw new \\Exception("x");');
        $this->assertStringContainsString('.a{color:red}', $this->renderCss($this->prodWithCache($dir), $css));
        $this->assertStringContainsString('.a{color:red}', (string) include $file);
    }

    public function test_a_miss_prunes_the_oldest_tenth_above_the_cap(): void
    {
        $dir = $this->cacheDir();
        mkdir($dir . '/assets', 0775, true);
        for ($i = 0; $i < 2001; $i++) {
            $f = sprintf('%s/assets/old%04d.php', $dir, $i);
            file_put_contents($f, "<?php return 'x';\n");
            touch($f, 1000000 + $i);
        }
        $this->renderCss($this->prodWithCache($dir), '.new { color: red; }');
        $this->assertFileDoesNotExist($dir . '/assets/old0000.php');
        $this->assertFileExists($dir . '/assets/old2000.php');
        $this->assertCount(2001 - 200 + 1, glob($dir . '/assets/*.php'));
    }
}
