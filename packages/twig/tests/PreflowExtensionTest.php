<?php

declare(strict_types=1);

namespace Preflow\Twig\Tests;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;
use Preflow\Twig\PreflowExtension;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Seo\SeoContext;

final class PreflowExtensionTest extends TestCase
{
    private AssetCollector $assets;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->assets = new AssetCollector(
            nonceGenerator: new NonceGenerator(),
        );

        $this->twig = new Environment(new ArrayLoader([]), [
            'autoescape' => false,
        ]);
        $this->twig->addExtension(new PreflowExtension($this->assets));
    }

    private function render(string $template, array $context = []): string
    {
        $tpl = $this->twig->createTemplate($template);
        return $tpl->render($context);
    }

    public function test_apply_css_filter_registers_css(): void
    {
        $this->render('{% apply css %}.box { padding: 1rem; }{% endapply %}');

        $output = $this->assets->renderCss();
        $this->assertStringContainsString('.box { padding: 1rem; }', $output);
    }

    public function test_apply_css_filter_returns_empty_string(): void
    {
        $result = $this->render('{% apply css %}.box { padding: 1rem; }{% endapply %}');

        $this->assertSame('', trim($result));
    }

    public function test_apply_js_filter_registers_body_js(): void
    {
        $this->render('{% apply js %}console.log("hello");{% endapply %}');

        $output = $this->assets->renderJsBody();
        $this->assertStringContainsString('console.log("hello")', $output);
    }

    public function test_apply_js_filter_returns_empty_string(): void
    {
        $result = $this->render('{% apply js %}console.log("hello");{% endapply %}');

        $this->assertSame('', trim($result));
    }

    public function test_apply_js_head_filter(): void
    {
        $this->render("{% apply js('head') %}window.CONFIG = {};{% endapply %}");

        $head = $this->assets->renderJsHead();
        $body = $this->assets->renderJsBody();

        $this->assertStringContainsString('window.CONFIG = {}', $head);
        $this->assertSame('', $body);
    }

    public function test_apply_js_inline_filter(): void
    {
        $this->render("{% apply js('inline') %}alert('now');{% endapply %}");

        $inline = $this->assets->renderJsInline();
        $this->assertStringContainsString("alert('now')", $inline);
    }

    public function test_head_function_renders_head_assets(): void
    {
        $this->assets->addJs('headScript();', JsPosition::Head);

        $result = $this->render('{{ head() }}');

        $this->assertStringContainsString('headScript()', $result);
    }

    public function test_assets_function_renders_body_js(): void
    {
        $this->assets->addCss('.page { margin: 0; }');
        $this->assets->addJs('init();');

        $result = $this->render('{{ assets() }}');

        $this->assertStringContainsString('init()', $result);
        $this->assertStringNotContainsString('.page', $result); // CSS is in head(), not assets()
    }

    public function test_css_dedup_across_multiple_renders(): void
    {
        $this->render('{% apply css %}.shared { display: flex; }{% endapply %}');
        $this->render('{% apply css %}.shared { display: flex; }{% endapply %}');

        $output = $this->assets->renderCss();
        $this->assertSame(1, substr_count($output, '.shared { display: flex; }'));
    }

    public function test_asset_url_prepends_leading_slash(): void
    {
        $result = $this->render("{{ asset_url('images/logo.png') }}");
        $this->assertSame('/images/logo.png', $result);
    }

    public function test_asset_url_does_not_double_slash(): void
    {
        $result = $this->render("{{ asset_url('/images/logo.png') }}");
        $this->assertSame('/images/logo.png', $result);
    }

    public function test_full_page_layout(): void
    {
        // Simulate a component registering assets
        $this->assets->addJs('configSetup();', JsPosition::Head);
        $this->assets->addCss('body { margin: 0; }');
        $this->assets->addJs('appInit();', JsPosition::Body);

        $result = $this->render(
            '<head>{{ head() }}</head><body>content{{ assets() }}</body>'
        );

        // CSS + head JS in <head>
        $this->assertMatchesRegularExpression('/<head>.*body \{ margin: 0; \}.*<\/head>/s', $result);
        $this->assertMatchesRegularExpression('/<head>.*configSetup\(\).*<\/head>/s', $result);
        // Body JS in <body>, CSS NOT in <body>
        $this->assertMatchesRegularExpression('/<body>.*appInit\(\).*<\/body>/s', $result);
        $this->assertDoesNotMatchRegularExpression('/<body>.*body \{ margin: 0; \}.*<\/body>/s', $result);
    }

    private function geoTwig(GeoCollector $geo): Environment
    {
        $twig = new Environment(new ArrayLoader([]), ['autoescape' => 'html']);
        $twig->addExtension(new PreflowExtension($this->assets, $geo));
        return $twig;
    }

    public function test_apply_geo_filter_outputs_nothing_and_records_trimmed_markdown(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $result = $this->geoTwig($geo)
            ->createTemplate("<p>Page</p>{% apply geo %}\n  ## {{ title }}\n{% endapply %}")
            ->render(['title' => 'About']);

        $this->assertSame('<p>Page</p>', $result);
        $this->assertSame(['## About'], $geo->pageBlocks());
    }

    public function test_apply_geo_filter_records_printed_values_as_plain_text(): void
    {
        $geo = new GeoCollector();
        $geo->capture(true);

        $this->geoTwig($geo)
            ->createTemplate('{% apply geo %}{{ name }}{% endapply %}')
            ->render(['name' => "Tom & Jerry's <b>"]);

        $this->assertSame(["Tom & Jerry's <b>"], $geo->pageBlocks());
    }

    public function test_apply_geo_filter_records_nothing_while_not_capturing(): void
    {
        $geo = new GeoCollector();

        $result = $this->geoTwig($geo)
            ->createTemplate('<p>Page</p>{% apply geo %}## Title{% endapply %}')
            ->render();

        $this->assertSame('<p>Page</p>', $result);
        $this->assertSame([], $geo->pageBlocks());
    }

    public function test_apply_geo_filter_without_collector_outputs_nothing(): void
    {
        $result = $this->render('<p>Page</p>{% apply geo %}## Title{% endapply %}');

        $this->assertSame('<p>Page</p>', $result);
    }

    public function test_seo_function_merges_into_the_context_and_prints_nothing(): void
    {
        $seo = new SeoContext();
        $twig = new Environment(new ArrayLoader([]), ['autoescape' => 'html']);
        $twig->addExtension(new PreflowExtension($this->assets, seoContext: $seo));

        $result = $twig->createTemplate(
            "<p>Page</p>{% do seo({title: 'Game night', description: 'Bring a game', image: '/img/share.jpg', og_type: 'article', schema: {'@type': 'Event', name: 'Game night'}}) %}"
            . "{{ seo({noindex: true, schema: [{'@type': 'Place'}]}) }}",
        )->render();

        $this->assertSame('<p>Page</p>', $result);
        $meta = $seo->get();
        $this->assertSame('Game night', $meta?->title);
        $this->assertSame('Bring a game', $meta->description);
        $this->assertSame(['url' => '/img/share.jpg'], $meta->image);
        $this->assertSame('article', $meta->ogType);
        $this->assertTrue($meta->noindex);
        // Each call's nodes are added to the earlier ones.
        $this->assertSame([['@type' => 'Event', 'name' => 'Game night'], ['@type' => 'Place']], $meta->schemas);
    }

    public function test_seo_function_without_context_is_a_no_op(): void
    {
        $this->assertSame('<p>Page</p>', $this->render("<p>Page</p>{% do seo({title: 'X'}) %}"));
    }
}
