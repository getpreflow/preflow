<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ComponentsExtensionProvider;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\Tests\Fixtures\Geo\GeoBroken;
use Preflow\Components\Tests\Fixtures\Geo\GeoCard;
use Preflow\Components\Tests\Fixtures\Geo\GeoEmptyBlock;
use Preflow\Components\Tests\Fixtures\Geo\GeoParentWithBlock;
use Preflow\Components\Tests\Fixtures\Geo\GeoParentWithoutBlock;
use Preflow\Components\Tests\Fixtures\Geo\GeoPlain;
use Preflow\Core\DebugLevel;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\NonceGenerator;

require_once __DIR__ . '/Fixtures/Geo/GeoComponents.php';

final class ComponentRendererGeoTest extends TestCase
{
    private GeoCollector $geo;

    protected function setUp(): void
    {
        $this->geo = new GeoCollector();
    }

    private function renderer(?GeoCollector $geo): ComponentRenderer
    {
        $assets = new AssetCollector(new NonceGenerator());
        $engine = new TwigEngine([], $assets, geoCollector: $geo);
        $renderer = new ComponentRenderer(
            $engine,
            new ErrorBoundary(debug: DebugLevel::Off),
            null,
            $assets,
            $geo,
        );

        $provider = new ComponentsExtensionProvider($renderer, [
            'GeoCard' => GeoCard::class,
        ]);
        foreach ($provider->getTemplateFunctions() as $function) {
            $engine->addFunction($function);
        }

        return $renderer;
    }

    /** @param array<string, mixed> $props */
    private function component(string $class, array $props = []): Component
    {
        $component = new $class();
        $component->setProps($props);
        return $component;
    }

    public function test_without_capture_html_is_unchanged(): void
    {
        foreach ([GeoCard::class, GeoPlain::class, GeoParentWithBlock::class, GeoParentWithoutBlock::class, GeoEmptyBlock::class] as $class) {
            $plain = $this->renderer(null)->render($this->component($class));
            $withCollector = $this->renderer($this->geo)->render($this->component($class));

            $this->assertSame($plain, $withCollector, $class);
            $this->assertStringNotContainsString('preflow-geo', $withCollector);
        }

        $this->assertSame('', $this->geo->block(0));
    }

    public function test_component_with_geo_block_renders_a_placeholder_when_capturing(): void
    {
        $this->geo->capture(true);
        $component = $this->component(GeoCard::class, ['title' => 'Tom & Jerry']);

        $html = $this->renderer($this->geo)->render($component);

        $this->assertMatchesRegularExpression(
            '#^<div id="[^"]+"><preflow-geo data-geo="0"></preflow-geo></div>$#',
            $html,
        );
        $this->assertSame('## Tom & Jerry', $this->geo->block(0));
    }

    public function test_component_without_geo_block_keeps_its_html_when_capturing(): void
    {
        $this->geo->capture(true);

        $html = $this->renderer($this->geo)->render($this->component(GeoPlain::class));

        $this->assertStringContainsString('<p>Plain</p>', $html);
        $this->assertStringNotContainsString('preflow-geo', $html);
    }

    public function test_parent_with_block_hides_child_html_and_child_block(): void
    {
        $this->geo->capture(true);

        $html = $this->renderer($this->geo)->render($this->component(GeoParentWithBlock::class));

        $this->assertMatchesRegularExpression(
            '#^<div id="[^"]+"><preflow-geo data-geo="(\d+)"></preflow-geo></div>$#',
            $html,
        );
        preg_match('#data-geo="(\d+)"#', $html, $m);
        $this->assertSame('Parent summary', $this->geo->block((int) $m[1]));
        $this->assertStringNotContainsString('Child', $html);
        $this->assertSame([], $this->geo->pageBlocks());
    }

    public function test_parent_without_block_keeps_child_placeholder_in_place(): void
    {
        $this->geo->capture(true);

        $html = $this->renderer($this->geo)->render($this->component(GeoParentWithoutBlock::class));

        $this->assertMatchesRegularExpression(
            '#^<div id="[^"]+"><section>Intro <div id="[^"]+"><preflow-geo data-geo="0"></preflow-geo></div></section></div>$#',
            $html,
        );
        $this->assertSame('## Child', $this->geo->block(0));
    }

    public function test_empty_block_gives_an_empty_placeholder(): void
    {
        $this->geo->capture(true);

        $html = $this->renderer($this->geo)->render($this->component(GeoEmptyBlock::class));

        $this->assertStringContainsString('<preflow-geo data-geo="0"></preflow-geo>', $html);
        $this->assertStringNotContainsString('Menu', $html);
        $this->assertSame('', $this->geo->block(0));
    }

    public function test_every_render_path_uses_frames_when_capturing(): void
    {
        $this->geo->capture(true);
        $renderer = $this->renderer($this->geo);

        $fragment = $renderer->renderFragment($this->component(GeoCard::class));
        $resolved = $this->component(GeoCard::class);
        $resolved->resolveState();
        $resolvedFragment = $renderer->renderResolvedFragment($resolved);
        $resolvedWrapped = $renderer->renderResolved($resolved);

        $this->assertSame('<preflow-geo data-geo="0"></preflow-geo>', $fragment);
        $this->assertSame('<preflow-geo data-geo="1"></preflow-geo>', $resolvedFragment);
        $this->assertMatchesRegularExpression('#^<div id="[^"]+"><preflow-geo data-geo="2"></preflow-geo></div>$#', $resolvedWrapped);
    }

    public function test_failing_render_closes_its_frame(): void
    {
        $this->geo->capture(true);

        $html = $this->renderer($this->geo)->render($this->component(GeoBroken::class));
        $this->geo->add('Page text');

        $this->assertStringNotContainsString('preflow-geo', $html);
        $this->assertSame(['Page text'], $this->geo->pageBlocks());
    }
}
