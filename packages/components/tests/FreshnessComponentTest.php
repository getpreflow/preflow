<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\Tests\Fixtures\Freshness\LiveReader;
use Preflow\Components\Tests\Fixtures\Freshness\MoreJobs;
use Preflow\Components\Tests\Fixtures\Freshness\OtherJobs;
use Preflow\Components\Tests\Fixtures\Freshness\PlainReader;
use Preflow\Components\Tests\Fixtures\Freshness\Reader;
use Preflow\Components\Tests\Fixtures\Freshness\Ticker;
use Preflow\Components\Tests\Fixtures\Freshness\TimedWithoutSeconds;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\Freshness;
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
use Preflow\Core\PageCache\PageCache;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

require_once __DIR__ . '/Fixtures/Freshness/Components.php';

/** #[Freshness]: around a component's resolveState() and render. */
final class FreshnessComponentTest extends TestCase
{
    private DependencyRecorder $recorder;

    private PageCache $cache;

    private ComponentRenderer $renderer;

    /** @var list<string> */
    private array $errors = [];

    protected function setUp(): void
    {
        $this->recorder = new DependencyRecorder();
        Reader::$recorder = $this->recorder;
        $this->cache = new PageCache(recorder: $this->recorder);
        $recorder = $this->recorder;
        $engine = new class($recorder) implements TemplateEngineInterface {
            public function __construct(private readonly DependencyRecorder $recorder) {}
            public function render(string $template, array $context = []): string
            {
                $this->recorder->record('render:' . ($context['state'] ?? ''));

                return '<b>' . ($context['state'] ?? '') . '</b>';
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $namespace, string $path): void {}
        };
        $this->renderer = new ComponentRenderer($engine, new ErrorBoundary(onError: function (\Throwable $e): void {
            $this->errors[] = $e->getMessage();
        }));
        $this->renderer->enableFreshness($this->cache);
    }

    protected function tearDown(): void
    {
        Reader::$recorder = null;
    }

    public function test_resolve_and_render_are_read_with_the_components_freshness(): void
    {
        [, $tags] = $this->recorder->capture(fn (): string => $this->renderer->render($this->make(OtherJobs::class, 'jobs'))
            . $this->renderer->render($this->make(Ticker::class, 'ticker'))
            . $this->renderer->render($this->make(MoreJobs::class, 'more'))
            . $this->renderer->render($this->make(PlainReader::class, 'plain'))
            . $this->renderer->renderFragment($this->make(OtherJobs::class, 'fragment'))
            . $this->renderer->renderResolved($this->resolved(OtherJobs::class, 'resolved')));

        $this->assertSame([
            'jobs' => [Freshness::NextVisit, null],
            'render:jobs' => [Freshness::NextVisit, null],
            'ticker' => [Freshness::Timed, 43200],
            'render:ticker' => [Freshness::Timed, 43200],
            'more' => [Freshness::NextVisit, null],
            'render:more' => [Freshness::NextVisit, null],
            'plain' => [Freshness::Fresh, null],
            'render:plain' => [Freshness::Fresh, null],
            'fragment' => [Freshness::NextVisit, null],
            'render:fragment' => [Freshness::NextVisit, null],
            'resolved' => [Freshness::Fresh, null], // resolved before, outside the renderer
            'render:resolved' => [Freshness::NextVisit, null],
        ], $tags);
        $this->assertSame([], $this->errors);
    }

    public function test_the_strictest_freshness_wins_when_a_tag_is_read_twice(): void
    {
        [, $tags] = $this->recorder->capture(fn (): string => $this->renderer->render($this->make(Ticker::class, 'default:job'))
            . $this->renderer->render($this->make(OtherJobs::class, 'default:job')));

        $this->assertSame([Freshness::NextVisit, null], $tags['default:job']);
    }

    public function test_a_scope_around_the_component_does_not_override_it_and_its_own_ends_with_it(): void
    {
        [, $tags] = $this->recorder->capture(fn (): string => $this->cache->within(Freshness::Timed, 60, function (): string {
            $html = $this->renderer->render($this->make(OtherJobs::class, 'inner'));
            $this->recorder->record('after');

            return $html;
        }));

        $this->assertSame([Freshness::NextVisit, null], $tags['inner']);
        $this->assertSame([Freshness::Timed, 60], $tags['after']);
    }

    public function test_without_the_page_cache_wired_the_attribute_does_nothing(): void
    {
        $renderer = new ComponentRenderer(new class implements TemplateEngineInterface {
            public function render(string $template, array $context = []): string { return 'x'; }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $namespace, string $path): void {}
        }, new ErrorBoundary());

        [, $tags] = $this->recorder->capture(fn (): string => $renderer->render($this->make(OtherJobs::class, 'jobs')));

        $this->assertSame([Freshness::Fresh, null], $tags['jobs']);
    }

    public function test_on_a_live_component_it_does_nothing(): void
    {
        $live = new LiveParts(new LivePlaceholder('freshness-test-key'), $this->cache, $this->recorder, static function (): void {});
        $this->renderer->enableLive($live, fn (string $class, array $props): Component => $this->make($class, (string) $props['tag']));
        $key = \Preflow\Core\PageCache\PageKey::fromRequest(
            new \Nyholm\Psr7\ServerRequest('GET', 'https://example.com/page'),
            new \Preflow\Core\PageCache\PageCacheConfig(enabled: true, hosts: ['example.com']),
        ) ?? throw new \RuntimeException('no key');

        // Stored: its placeholder, nothing read.
        $frame = $this->recorder->start();
        $before = $this->cache->begin($key);
        try {
            $stored = $this->renderer->render($this->make(LiveReader::class, 'personal'));
        } finally {
            $this->cache->end($before);
        }
        $this->recorder->record('page');
        $this->assertSame(['page' => [Freshness::Fresh, null]], $this->recorder->stop($frame));

        // Served: filled outside every recording, its freshness no dependency either.
        $frame = $this->recorder->start();
        $this->assertStringContainsString('<b>personal</b>', $live->fill($stored));
        $this->recorder->record('page');
        $this->assertSame(['page' => [Freshness::Fresh, null]], $this->recorder->stop($frame));
        $this->assertNull(ComponentRenderer::freshnessOf(PlainReader::class));
    }

    public function test_timed_without_seconds_fails_into_the_error_boundary(): void
    {
        $this->renderer->render($this->make(TimedWithoutSeconds::class, 'x'));

        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('needs its seconds', $this->errors[0]);
    }

    /** @param class-string<Component> $class */
    private function make(string $class, string $tag): Component
    {
        $component = new $class();
        $component->setProps(['tag' => $tag]);

        return $component;
    }

    /** @param class-string<Component> $class */
    private function resolved(string $class, string $tag): Component
    {
        $component = $this->make($class, $tag);
        $component->resolveState();

        return $component;
    }
}
