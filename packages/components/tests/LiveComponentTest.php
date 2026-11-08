<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\Tests\Fixtures\Live\AlbumButton;
use Preflow\Components\Tests\Fixtures\Live\PlainCard;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

require_once __DIR__ . '/Fixtures/Live/AlbumButton.php';
require_once __DIR__ . '/Fixtures/Live/PlainCard.php';
require_once __DIR__ . '/Fixtures/Live/BrokenPrefetch.php';

/** Components marked #[Live]: placeholders while storing, rendered per visitor, prefetched per class. */
final class LiveComponentTest extends TestCase
{
    private PageCache $cache;

    private LiveParts $live;

    private ComponentRenderer $renderer;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        AlbumButton::reset();
        $this->cache = new PageCache();
        $this->live = new LiveParts(new LivePlaceholder('live-test-key'), $this->cache, new DependencyRecorder(), function (string $m): void {
            $this->logged[] = $m;
        });
        $engine = new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string { return '<b>' . ($context['state'] ?? '') . '</b>'; }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $namespace, string $path): void {}
        };
        $cache = $this->cache;
        $this->renderer = new ComponentRenderer($engine, new ErrorBoundary(onError: static function () use ($cache): void {
            $cache->refuse('component-error');
        }));
        $this->renderer->enableLive($this->live, $this->factory(...));
    }

    public function test_live_components_of_one_class_are_prefetched_once(): void
    {
        $stored = $this->storing(function (): string {
            $html = '';
            for ($n = 1; $n <= 24; $n++) {
                $html .= $this->renderer->render($this->factory(AlbumButton::class, ['number' => $n, 'key' => (string) $n]));
            }

            return $html . $this->renderer->render($this->factory(PlainCard::class, []));
        });
        $this->assertSame(24, substr_count($stored, '<!--pc:l:'));
        $this->assertStringContainsString('<b>plain</b>', $stored, 'a component that is not live renders in place');
        $this->assertSame([], AlbumButton::$prefetched, 'nothing is loaded while storing');

        AlbumButton::$album = [3, 20];
        $filled = $this->live->fill($stored);
        $this->assertCount(1, AlbumButton::$prefetched, 'one prefetchLive() call for the 24');
        $this->assertSame(range(1, 24), array_column(AlbumButton::$prefetched[0], 'number'));
        $this->assertStringNotContainsString('(unloaded)', $filled);
        $this->assertStringContainsString('<b>3 in your album</b>', $filled);
        $this->assertStringContainsString('<b>4 add</b>', $filled);
        $this->assertStringContainsString('<b>20 in your album</b>', $filled);
        $this->assertSame(1, preg_match('/<div id="AlbumButton-[0-9a-f]+-7"><b>7 add<\/b><\/div>/', $filled), 'with its wrapper, as in place');
    }

    public function test_a_live_component_renders_in_place_without_the_cache(): void
    {
        AlbumButton::$album = [5];

        $html = $this->renderer->render($this->factory(AlbumButton::class, ['number' => 5]));
        $this->assertStringContainsString('<b>5 in your album (unloaded)</b>', $html);
        $this->assertStringNotContainsString('<!--pc:', $html);
    }

    public function test_props_are_scalars_and_arrays(): void
    {
        $html = $this->storing(fn (): string => $this->renderer->render($this->factory(AlbumButton::class, ['number' => new \ArrayObject()])));

        $this->assertStringContainsString('data-component-error', $html, 'the boundary');
        $this->assertSame('component-error', $this->refusalWhile(fn () => $this->renderer->render($this->factory(AlbumButton::class, ['at' => new \DateTimeImmutable()]))));
    }

    public function test_a_failing_live_component_is_its_boundary_and_refuses_nothing(): void
    {
        $stored = $this->storing(fn (): string => '<p>' . $this->renderer->render($this->factory(AlbumButton::class, ['number' => 1])) . '</p>');
        AlbumButton::$fail = true;

        $before = $this->cache->begin($this->key());
        $this->assertSame('<p><div style="display:none;" data-component-error="true"></div></p>', $this->live->fill($stored));
        $this->assertNull($this->cache->refusal());
        $this->cache->end($before);
    }

    public function test_a_signed_placeholder_naming_a_component_that_is_not_live_is_not_rendered(): void
    {
        $marker = (new LivePlaceholder('live-test-key'))->make(ComponentRenderer::LIVE_KIND, ['c' => PlainCard::class, 'p' => []]);
        $notAComponent = (new LivePlaceholder('live-test-key'))->make(ComponentRenderer::LIVE_KIND, ['c' => \stdClass::class, 'p' => []]);

        $this->assertSame("<p>{$marker}{$notAComponent}</p>", $this->live->fill("<p>{$marker}{$notAComponent}</p>"), 'inert comments');
        $this->assertSame(['page cache: 2 live marker(s) left as comments: unsigned, forged or naming no declared live part'], $this->logged);
    }

    public function test_a_failing_prefetch_keeps_no_other_class_from_its_prefetch(): void
    {
        $stored = $this->storing(fn (): string => $this->renderer->render($this->factory(\Preflow\Components\Tests\Fixtures\Live\BrokenPrefetch::class, []))
            . $this->renderer->render($this->factory(AlbumButton::class, ['number' => 1])));

        $filled = $this->live->fill($stored);
        $this->assertCount(1, AlbumButton::$prefetched, 'the class after it still prefetched');
        $this->assertStringContainsString('<b>broken-prefetch</b>', $filled);
        $this->assertStringContainsString('<b>1 add</b>', $filled);
        $this->assertStringContainsString('prefetch down', $this->logged[0] ?? '');
    }

    /** @param array<string, mixed> $props */
    private function factory(string $class, array $props): Component
    {
        $component = new $class();
        $component->setProps($props);

        return $component;
    }

    private function storing(\Closure $render): string
    {
        $before = $this->cache->begin($this->key());
        try {
            return $render();
        } finally {
            $this->cache->end($before);
        }
    }

    private function refusalWhile(\Closure $render): ?string
    {
        $before = $this->cache->begin($this->key());
        try {
            $render();

            return $this->cache->refusal();
        } finally {
            $this->cache->end($before);
        }
    }

    private function key(): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/page'), new PageCacheConfig(enabled: true, hosts: ['example.com']))
            ?? throw new \RuntimeException('no key');
    }
}
