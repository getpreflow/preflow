<?php

declare(strict_types=1);

namespace Preflow\View\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\View\LazyTemplateEngine;
use Preflow\View\Regions;
use Preflow\View\RendersRegions;
use Preflow\View\SharingTemplateEngine;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/** The region element every engine prints, and the wrapping engines passing regions on. */
final class RegionsTest extends TestCase
{
    public function test_open_prints_the_region_element(): void
    {
        $this->assertSame('<div id="c1-results" class="a&amp;b" hidden data-n="2">', Regions::open(['componentId' => 'c1'], 'results', ['class' => 'a&b', 'hidden' => true, 'open' => false, 'title' => null, 'data-n' => 2]));
        $this->assertSame('</div>', Regions::close());
    }

    public function test_open_refuses_what_it_cannot_print(): void
    {
        foreach ([
            [[], 'r', [], 'componentId'],
            [['componentId' => 'c'], '1r', [], 'no region name'],
            [['componentId' => 'c'], 'r', ['id' => 'x'], "attribute 'id'"],
            [['componentId' => 'c'], 'r', ['on click' => 'x'], "attribute 'on click'"],
            [['componentId' => 'c'], 'r', ['class' => ['a']], 'not text'],
            [['componentId' => 'c'], 'r', 'class', 'a mapping'],
        ] as [$context, $name, $attributes, $message]) {
            try {
                Regions::open($context, $name, $attributes);
                $this->fail('opened: ' . $message);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function test_wrapping_engines_pass_regions_on(): void
    {
        $regions = $this->engine(true);

        foreach ([new SharingTemplateEngine($regions), new LazyTemplateEngine(static fn () => $regions, 'phtml')] as $engine) {
            $this->assertInstanceOf(RendersRegions::class, $engine);
            $this->assertSame(['results'], $engine->regionsOf('x.phtml'));
            $this->assertSame('results of x.phtml for c', $engine->renderRegion('x.phtml', 'results', ['componentId' => 'c']));
        }
    }

    public function test_wrapping_an_engine_without_regions_names_it(): void
    {
        $plain = $this->engine(false);

        foreach ([new SharingTemplateEngine($plain), new LazyTemplateEngine(static fn () => $plain, 'phtml')] as $engine) {
            try {
                $engine->regionsOf('x.phtml');
                $this->fail('regions of an engine without them');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('TemplateEngineInterface@anonymous', $e->getMessage());
                $this->assertStringContainsString('renders no regions', $e->getMessage());
            }
        }
    }

    private function engine(bool $regions): TemplateEngineInterface
    {
        $plain = new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string { return $template; }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'phtml'; }
            public function addNamespace(string $namespace, string $path): void {}
        };

        return !$regions ? $plain : new class implements TemplateEngineInterface, RendersRegions {
            public function render(string $template, array $context = []): string { return $template; }
            public function renderRegion(string $template, string $region, array $context): string
            {
                return $region . ' of ' . $template . ' for ' . $context['componentId'];
            }
            public function regionsOf(string $template): array { return ['results']; }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'phtml'; }
            public function addNamespace(string $namespace, string $path): void {}
        };
    }
}
