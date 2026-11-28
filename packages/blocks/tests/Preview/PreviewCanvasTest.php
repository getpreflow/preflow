<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Preview;

use Preflow\Blocks\Preview\PreviewCanvas;
use Preflow\Blocks\Preview\PreviewView;
use Preflow\Blocks\Preview\TypePreview;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Blocks\Tests\Fixtures\Preview\PreviewBroken;
use Preflow\Blocks\Tests\Fixtures\Preview\PreviewTeaser;
use Preflow\Components\ComponentMap;
use Preflow\Components\ComponentRenderer;
use Preflow\Core\Context\Contexts;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;

/**
 * PreviewCanvas: a record rendered through a preview view's component (props of call 5: record,
 * type, view, locale, preview), then the canvas template around it (call 6), then the collected
 * assets put into the page. The caller opens the render context; here a visitor's in "de".
 */
final class PreviewCanvasTest extends BlocksAppTestCase
{
    private const string CANVAS = "<!doctype html><html lang=\"{{ locale }}\"><head><title>{{ type }}</title></head>"
        . "<body data-view=\"{{ view }}\" data-title=\"{{ record.title }}\"><main class=\"canvas\">{{ preview }}</main></body></html>";

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('canvas.twig', self::CANVAS);
    }

    /** A canvas over the app's services, with $map as the component map. */
    private function canvas(array $map = []): PreviewCanvas
    {
        $c = $this->app()->container();

        return new PreviewCanvas(
            $c,
            $c->get(ComponentRenderer::class),
            $c->get(TemplateEngineInterface::class),
            new ComponentMap($map),
            $c->get(AssetCollector::class),
        );
    }

    private function preview(string $component): TypePreview
    {
        return new TypePreview('job', [
            'card' => new PreviewView('card', 'Card', $component),
            'wide' => new PreviewView('wide', 'Wide', $component),
        ]);
    }

    private function inVisit(callable $render): string
    {
        return Contexts::run($this->visit()->in('de'), $render);
    }

    public function test_renders_the_views_component_with_the_record_props(): void
    {
        $canvas = $this->canvas(['Teaser' => PreviewTeaser::class]);

        $html = $this->inVisit(fn (): string => $canvas->render($this->preview('Teaser'), 'wide', ['title' => 'Gärtner <m/w>'], 'de', 'canvas.twig'));

        $this->assertStringContainsString('<article class="teaser" data-type="job" data-view="wide" data-locale="de" data-preview="yes" data-props="locale,preview,record,type,view">', $html, 'a name in the component map; nothing but the five props');
        $this->assertStringContainsString('<h2>Gärtner &lt;m/w&gt;</h2>', $html, 'the record as given, escaped by the component');
    }

    public function test_canvas_template_receives_preview_record_type_view_and_locale(): void
    {
        $canvas = $this->canvas(['Teaser' => PreviewTeaser::class]);

        // An unknown view key is the first view (TypePreview::view()).
        $html = $this->inVisit(fn (): string => $canvas->render($this->preview('Teaser'), 'gone', ['title' => 'Koch'], 'de', 'canvas.twig'));

        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('<title>job</title>', $html);
        $this->assertStringContainsString('<body data-view="card" data-title="Koch">', $html);
        $this->assertMatchesRegularExpression('~<main class="canvas"><div id="PreviewTeaser-[^"]+"><article class="teaser"[^>]*data-view="card"~', $html, 'the component HTML printed unescaped');
    }

    public function test_component_css_and_js_are_injected_into_the_canvas(): void
    {
        $canvas = $this->canvas(['Teaser' => PreviewTeaser::class]);

        $html = $this->inVisit(fn (): string => $canvas->render($this->preview('Teaser'), null, ['title' => 'Koch'], 'de', 'canvas.twig'));

        $head = substr($html, 0, (int) stripos($html, '</head>'));
        $this->assertStringContainsString('rebeccapurple', $head, 'the component CSS in the head');
        $body = substr($html, (int) stripos($html, '<body'));
        $this->assertStringContainsString('window.teaserLoaded = true;', $body, 'the component JS in the body');
        $this->assertLessThan(strripos($html, '</body>'), strpos($html, 'window.teaserLoaded'));
    }

    public function test_a_failing_component_renders_its_boundary_inside_the_canvas(): void
    {
        $canvas = $this->canvas();

        $html = $this->inVisit(fn (): string => $canvas->render($this->preview(PreviewBroken::class), 'card', ['title' => 'Koch'], 'de', 'canvas.twig'));

        $this->assertStringStartsWith('<!doctype html>', $html, 'the canvas still renders');
        $main = substr($html, (int) strpos($html, '<main class="canvas">'));
        $this->assertStringContainsString('The teaser could not load its record.', $main, 'the boundary (debug app) inside the canvas');
        $this->assertStringNotContainsString('<p>never</p>', $html);
    }

    public function test_works_without_folio(): void
    {
        $this->assertFalse(class_exists(\Preflow\Folio\FolioServiceProvider::class, false) && $this->app()->container()->bound(\Preflow\Folio\FolioServiceProvider::class));
        $canvas = $this->app()->container()->get(PreviewCanvas::class);
        $this->assertInstanceOf(PreviewCanvas::class, $canvas, 'bound by preflow/blocks alone');

        $html = $this->inVisit(fn (): string => $canvas->render(new TypePreview('job', ['default' => new PreviewView('default', null, PreviewTeaser::class)]), null, ['title' => 'Koch'], 'de', 'canvas.twig'));

        $this->assertMatchesRegularExpression('~<main class="canvas"><div id="PreviewTeaser-[^"]+"><article class="teaser" data-type="job" data-view="default"~', $html, 'a class name as the component');
        $this->assertStringContainsString('rebeccapurple', $html);
    }
}
