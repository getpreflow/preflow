<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;
use Preflow\View\NonceGenerator;
use Preflow\View\Twig\PreflowExtension;

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
}
