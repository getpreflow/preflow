<?php

declare(strict_types=1);

namespace Preflow\Components\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\SessionStateStore;
use Preflow\Components\SignedStateStore;
use Preflow\Components\StateRefused;
use Preflow\Components\StateStore;
use Preflow\Components\Tests\Fixtures\State\Counter;
use Preflow\Components\Tests\Fixtures\State\FreshCounter;
use Preflow\Components\Tests\Fixtures\State\LiveCounter;
use Preflow\Components\Tests\Fixtures\State\Log;
use Preflow\Components\Tests\Fixtures\State\PlainResolver;
use Preflow\Components\Tests\Fixtures\State\RowsForm;
use Preflow\Components\Tests\Fixtures\State\SessionCounter;
use Preflow\Components\Tests\Fixtures\State\StatefulResolver;
use Preflow\Core\DebugLevel;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\LivePlaceholder;
use Preflow\Core\PageCache\PageCache;
use Preflow\Core\PageCache\PageCacheConfig;
use Preflow\Core\PageCache\PageKey;
use Preflow\Testing\ArraySession;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

require_once __DIR__ . '/Fixtures/State/StateComponents.php';

/** #[State] components: mount() on the first render, prepare() before every render, the render stack. */
final class ComponentStateLifecycleTest extends TestCase
{
    private const string SECRET = 'state-lifecycle-test-secret-0123456789';

    private PageCache $cache;

    private ComponentRenderer $renderer;

    /** @var array<string, \Closure(array<string, mixed>): string> template => render */
    private array $templates = [];

    protected function setUp(): void
    {
        Log::$calls = [];
        $this->cache = new PageCache();
        $this->renderer = $this->renderer();
        $this->templates = [
            'Counter' => static fn (array $c): string => '<i>' . ($c['label'] ?? '') . '</i>',
            'Plain' => static fn (array $c): string => '<i>' . $c['label'] . '</i>',
            'RowsForm' => fn (array $c): string => implode(',', $c['rows'])
                . $this->renderer->render($this->make(Counter::class, ['start' => 5, 'key' => 'child'])),
        ];
    }

    public function test_first_render_runs_mount_then_prepare(): void
    {
        $html = $this->renderer->render($this->make(Counter::class, ['start' => 3]));

        $this->assertSame([Counter::class . ':mount', Counter::class . ':prepare'], Log::$calls);
        $this->assertStringContainsString('<i>count 3</i>', $html);
    }

    public function test_component_without_state_still_resolves_before_render(): void
    {
        $component = $this->make(PlainResolver::class, []);

        $this->assertFalse($component->hasState());
        $this->assertStringContainsString('<i>plain</i>', $this->renderer->render($component));
        $this->assertStringContainsString('<i>plain</i>', $this->renderer->renderFragment($this->make(PlainResolver::class, [])));
        $this->assertSame(['PlainResolver:resolveState', 'PlainResolver:resolveState'], Log::$calls);
        $this->assertNull($this->renderer->stateFor($component));
    }

    public function test_resolve_state_on_a_stateful_component_fails_in_debug(): void
    {
        try {
            $this->renderer->render($this->make(StatefulResolver::class, []));
            $this->fail('a stateful component with resolveState() renders in debug');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(StatefulResolver::class, $e->getMessage());
            $this->assertStringContainsString('use mount() and prepare()', $e->getMessage());
        }

        $production = $this->renderer(DebugLevel::Off);
        $this->assertStringContainsString('<i></i>', $production->render($this->make(StatefulResolver::class, [])), 'renders in production');
        $this->assertSame([], Log::$calls, 'resolveState() never runs on a component with state');
    }

    public function test_nested_component_starts_from_props_when_parent_renders_again(): void
    {
        $this->renderer->render($this->make(RowsForm::class, []));

        // The child's own action: restored from its state, not mounted again.
        $child = $this->make(Counter::class, ['key' => 'child']);
        $this->renderer->restore($child, $this->carriedFor(Counter::class, $child->getComponentId(), ['count' => 9]));
        $child->handleAction('add');
        Log::$calls = [];
        $this->assertStringContainsString('<i>count 10</i>', $this->renderer->renderAfterAction($child));
        $this->assertSame([Counter::class . ':prepare'], Log::$calls, 'no mount() after its own action');

        // The parent's action: its children start from their props again.
        $parent = $this->make(RowsForm::class, []);
        $this->renderer->restore($parent, $this->carriedFor(RowsForm::class, $parent->getComponentId(), ['rows' => ['first', 'second']]));
        Log::$calls = [];
        $html = $this->renderer->renderAfterAction($parent);

        $this->assertStringContainsString('first,second', $html);
        $this->assertStringContainsString('<i>count 5</i>', $html, 'the child mounted from its props');
        $this->assertSame(['RowsForm:prepare', Counter::class . ':mount', Counter::class . ':prepare'], Log::$calls);
    }

    public function test_current_and_parent_during_nested_render(): void
    {
        $seen = [];
        $renderer = $this->renderer;
        $this->templates['RowsForm'] = function (array $c) use (&$seen, $renderer): string {
            $seen['parent-current'] = $renderer->current();
            $html = $renderer->render($this->make(Counter::class, ['key' => 'child']));
            $seen['after-child'] = $renderer->current();

            return $html;
        };
        $this->templates['Counter'] = static function (array $c) use (&$seen, $renderer): string {
            $seen['child-current'] = $renderer->current();
            $seen['child-parent'] = $renderer->parentOf($renderer->current() ?? throw new \LogicException('no current'));

            return '';
        };

        $parent = $this->make(RowsForm::class, []);
        $this->assertNull($this->renderer->current());
        $this->renderer->render($parent);

        $this->assertSame($parent, $seen['parent-current']);
        $this->assertInstanceOf(Counter::class, $seen['child-current']);
        $this->assertSame($parent, $seen['child-parent']);
        $this->assertSame($parent, $seen['after-child']);
        $this->assertNull($this->renderer->current(), 'the stack is empty after the render');
        $this->assertNull($this->renderer->parentOf($parent), 'a top-level component has no parent');

        // Also while an action's response renders.
        $again = $this->make(RowsForm::class, []);
        $this->renderer->restore($again, $this->carriedFor(RowsForm::class, $again->getComponentId(), ['rows' => []]));
        $this->renderer->renderAfterAction($again);
        $this->assertSame($again, $seen['parent-current']);
        $this->assertSame($again, $seen['child-parent']);
        $this->assertNull($this->renderer->current());
    }

    public function test_equal_state_renders_equal_html(): void
    {
        $first = $this->make(Counter::class, ['start' => 4, 'key' => 'a']);
        $second = $this->make(Counter::class, ['start' => 4, 'key' => 'a']);

        $this->assertSame($this->renderer->render($first), $this->renderer->render($second));
        $this->assertNotNull($this->renderer->stateFor($first));
        $this->assertSame($this->renderer->stateFor($first), $this->renderer->stateFor($second), 'the carried state too');

        $restored = $this->make(Counter::class, ['key' => 'a']);
        $this->renderer->restore($restored, (string) $this->renderer->stateFor($first));
        $this->assertSame(4, $restored->count);
    }

    public function test_restore_refuses_foreign_state(): void
    {
        $this->renderer->render($other = $this->make(Counter::class, ['key' => 'other']));

        $this->expectException(StateRefused::class);
        $this->renderer->restore($this->make(Counter::class, ['key' => 'mine']), (string) $this->renderer->stateFor($other));
    }

    public function test_region_waits_for_a_template_engine_that_renders_regions(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('regions need a template engine that renders regions');
        $this->renderer->renderAfterAction($this->make(Counter::class, []), 'results');
    }

    public function test_live_and_freshness_components_with_state_still_render(): void
    {
        $live = new LiveParts(new LivePlaceholder('live-test-key-0123456789abcdef0123456789'), $this->cache, new DependencyRecorder(), static function (): void {});
        $this->renderer->enableLive($live, $this->make(...));
        $this->renderer->enableFreshness($this->cache);

        $stored = $this->storing(fn (): string => $this->renderer->render($this->make(LiveCounter::class, ['start' => 2]))
            . $this->renderer->render($this->make(FreshCounter::class, ['start' => 7])));
        $this->assertStringContainsString('<!--pc:l:', $stored, 'the live one is its placeholder');
        $this->assertStringContainsString('<i>count 7</i>', $stored, 'the fresh one renders in place');
        $this->assertNull($this->cache->refusal());

        $filled = $live->fill($stored);
        $this->assertStringContainsString('<i>count 2</i>', $filled, 'the live one mounts from its props per visitor');
        $this->assertSame([FreshCounter::class . ':mount', FreshCounter::class . ':prepare', LiveCounter::class . ':mount', LiveCounter::class . ':prepare'], Log::$calls);
    }

    public function test_session_store_on_a_cacheable_anonymous_page_fails_in_debug(): void
    {
        try {
            $this->storing(fn (): string => $this->renderer->render($this->make(SessionCounter::class, [])));
            $this->fail('a session store rendered on a page the cache may store');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(SessionCounter::class, $e->getMessage());
            $this->assertStringContainsString(SignedStateStore::class, $e->getMessage());
            $this->assertStringContainsString('not cached', $e->getMessage());
        }

        // Not stored: renders, with its reference.
        $this->assertStringContainsString('<i>count 0</i>', $this->renderer->render($component = $this->make(SessionCounter::class, [])));
        $this->assertNotNull($this->renderer->stateFor($component));
    }

    public function test_session_store_on_a_cacheable_page_keeps_the_page_from_being_stored_in_production(): void
    {
        $production = $this->renderer(DebugLevel::Off);
        $before = $this->cache->begin($this->key());
        try {
            $this->assertStringContainsString('<i>count 0</i>', $production->render($this->make(SessionCounter::class, [])));
            $this->assertSame('component-session-state', $this->cache->refusal());
        } finally {
            $this->cache->end($before);
        }
    }

    private function renderer(DebugLevel $debug = DebugLevel::On): ComponentRenderer
    {
        $engine = new class(fn (string $template, array $context): string => ($this->templates[$template])($context)) implements TemplateEngineInterface {
            /** @param \Closure(string, array<string, mixed>): string $templates */
            public function __construct(private readonly \Closure $templates) {}
            public function render(string $template, array $context = []): string
            {
                return ($this->templates)($template, $context);
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $namespace, string $path): void {}
        };
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: $debug));
        $signed = new SignedStateStore(self::SECRET);
        $session = new SessionStateStore(new ArraySession());
        $renderer->enableFreshness($this->cache);
        $renderer->enableState(
            static fn (string $store): StateStore => $store === SessionStateStore::class ? $session : $signed,
            $debug->isDebug(),
        );

        return $renderer;
    }

    /**
     * @param class-string<Component> $class
     * @param array<string, mixed> $props
     */
    private function make(string $class, array $props): Component
    {
        $component = new $class();
        $component->setProps($props);

        return $component;
    }

    /** @param array<string, mixed> $state */
    private function carriedFor(string $class, string $id, array $state): string
    {
        return (new SignedStateStore(self::SECRET))->put($class, $id, $state);
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

    private function key(): PageKey
    {
        return PageKey::fromRequest(new ServerRequest('GET', 'https://example.com/page'), new PageCacheConfig(enabled: true, hosts: ['example.com']))
            ?? throw new \RuntimeException('no key');
    }
}
