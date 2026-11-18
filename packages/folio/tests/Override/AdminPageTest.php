<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Override;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Folio\Extension\ExtensionAssets;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Extension\Screen\PageAssets;
use Preflow\Folio\Extension\Screen\ScreenView;
use Preflow\Folio\Http\AdminAssets;
use Preflow\Folio\Http\AdminAssetUrls;
use Preflow\Folio\Http\AdminPage;
use Preflow\Folio\Tests\Support\ExtensionAssetsSite;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

final class AdminPageTest extends TestCase
{
    use ExtensionAssetsSite;

    private PageAssets $pages;
    private RecordingEngine $engine;

    protected function setUp(): void
    {
        $folio = new AdminAssets(['htmx.js' => 'htmx.js']);
        $this->pages = new PageAssets(new ExtensionAssets($this->assetsSite(), '/folio'), $folio, new AdminAssetUrls('/folio', $this->site, $folio));
        $this->siteWrite('htmx.js', 'htmx');
        $this->siteWrite('shop/assets/shop.css', 'a{}');
        $this->engine = new RecordingEngine();
    }

    protected function tearDown(): void
    {
        $this->removeAssetsSite();
    }

    private function request(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequest('GET', '/folio')
            ->withAttribute(CsrfToken::class, CsrfToken::stateless('tok-123'));
    }

    public function test_a_view_gets_prefix_csrf_and_resolved_assets_like_a_screen(): void
    {
        $page = new AdminPage($this->engine, '/folio', $this->pages);
        $res = $page->render(new ScreenView('@shop/x.twig', ['n' => 3, 'prefix' => 'lost'], ['@folio/htmx.js']), $this->request(), 'Override X', 'shop', ['shop.css'], ['label' => 'Orders', 'path' => 'orders']);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $vars = $this->engine->vars;
        $this->assertSame(3, $vars['n']);
        $this->assertSame('/folio', $vars['prefix'], "Folio's variables win");
        $this->assertSame('tok-123', $vars['csrf']);
        $this->assertSame('/folio/_assets/x/shop/', $vars['extension_assets_base']);
        $this->assertTrue($vars['page_assets']['htmx']);
        $this->assertMatchesRegularExpression('#^/folio/_assets/x/shop/shop\.css\?v=#', $vars['page_assets']['styles'][0]);
        $this->assertSame(['label' => 'Orders', 'path' => 'orders'], $vars['screen']);
    }

    public function test_without_an_owner_extension_folio_and_named_extension_assets_resolve(): void
    {
        $page = new AdminPage($this->engine, '/folio', $this->pages);
        $page->render(new ScreenView('@folio/x.twig', [], ['@folio/htmx.js', '@shop/shop.css']), $this->request(), 'Override Content\\Index');

        $this->assertTrue($this->engine->vars['page_assets']['htmx']);
        $this->assertCount(1, $this->engine->vars['page_assets']['styles']);
        $this->assertNull($this->engine->vars['extension_assets_base']);
    }

    public function test_a_bare_asset_without_an_owner_names_the_override(): void
    {
        $page = new AdminPage($this->engine, '/folio', $this->pages);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Override Content\\Index lists asset "file.css"');
        $page->render(new ScreenView('@folio/x.twig', [], ['file.css']), $this->request(), 'Override Content\\Index');
    }

    public function test_a_missing_template_names_the_owner(): void
    {
        $this->engine->missing = true;
        $page = new AdminPage($this->engine, '/folio', $this->pages);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Override Y renders "@shop/none.twig", which does not exist.');
        $page->render(new ScreenView('@shop/none.twig'), $this->request(), 'Override Y');
    }
}

final class RecordingEngine implements TemplateEngineInterface
{
    /** @var array<string, mixed> */
    public array $vars = [];
    public bool $missing = false;

    public function render(string $template, array $context = []): string
    {
        $this->vars = $context;

        return 'PAGE';
    }

    public function exists(string $template): bool
    {
        return !$this->missing;
    }

    public function addFunction(TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }

    public function addNamespace(string $namespace, string $path): void {}
}
