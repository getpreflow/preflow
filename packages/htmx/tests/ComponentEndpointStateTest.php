<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests\State;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\SessionStateStore;
use Preflow\Components\SignedStateStore;
use Preflow\Components\State;
use Preflow\Components\StateIn;
use Preflow\Components\StateRefused;
use Preflow\Components\StateStore;
use Preflow\Core\DebugLevel;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Htmx\ComponentEndpoint;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Testing\ArraySession;
use Preflow\View\RendersRegions;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;
use Psr\Http\Message\ResponseInterface;

/** Writes each hook it runs. */
final class Hooks
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var array<string, mixed>|null */
    public static ?array $params = null;
}

/** A counter with state: mounted from "start", a label derived in prepare(). */
class StateCounter extends Component
{
    #[State] public int $count = 0;

    public string $label = '';

    public function mount(): void
    {
        Hooks::$calls[] = 'mount';
        $this->count = (int) ($this->props['start'] ?? 0);
    }

    public function prepare(): void
    {
        Hooks::$calls[] = 'prepare';
        $this->label = 'count ' . $this->count;
    }

    public function actions(): array
    {
        return ['add', 'echo', 'fail'];
    }

    public function actionAdd(array $params = []): void
    {
        Hooks::$calls[] = 'add';
        $this->count++;
    }

    /** Keeps what it was given, as input. */
    public function actionEcho(array $params = []): void
    {
        Hooks::$params = $params;
    }

    public function actionFail(array $params = []): void
    {
        throw new \RuntimeException('action failed');
    }

    public function fallback(\Throwable $e): ?string
    {
        return '<div class="fallback">failed</div>';
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'Counter';
    }
}

/** resolveState() on a component with state: never runs (production renderer). */
final class ResolvingCounter extends StateCounter
{
    public function resolveState(): void
    {
        Hooks::$calls[] = 'resolveState';
    }
}

/** Its mount() fails. */
final class FailingMountCounter extends StateCounter
{
    public function mount(): void
    {
        throw new \RuntimeException('boom');
    }

    public function fallback(\Throwable $e): ?string
    {
        return null;
    }
}

#[StateIn(SessionStateStore::class)]
final class SessionCounter extends StateCounter
{
}

/** Its region fails to render. */
final class BrokenRegionCounter extends StateCounter
{
    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'Broken';
    }
}

/** Without #[State]: resolveState() before every render, as in 0.85. */
final class PlainCounter extends Component
{
    public int $count = 0;

    public string $label = '';

    public function resolveState(): void
    {
        Hooks::$calls[] = 'resolveState';
        $this->count = (int) ($this->props['start'] ?? 0);
        $this->label = 'plain ' . $this->count;
    }

    public function actions(): array
    {
        return ['add', 'echo'];
    }

    public function actionAdd(array $params = []): void
    {
        $this->count++;
    }

    public function actionEcho(array $params = []): void
    {
        Hooks::$params = $params;
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'Counter';
    }
}

/** A template engine with one region, "results", in every template. */
final class RegionEngine implements TemplateEngineInterface, RendersRegions
{
    public function render(string $template, array $context = []): string
    {
        return '<p>' . $context['label'] . '</p><div id="' . $context['componentId'] . '-results">' . $this->renderRegion($template, 'results', $context) . '</div>';
    }

    public function renderRegion(string $template, string $region, array $context): string
    {
        if ($template === 'Broken') {
            throw new \RuntimeException('region failed');
        }

        return '<b>results ' . $context['count'] . '</b>';
    }

    public function regionsOf(string $template): array
    {
        return ['results'];
    }

    public function exists(string $template): bool
    {
        return true;
    }

    public function addFunction(TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }

    public function addNamespace(string $namespace, string $path): void {}
}

/** A template engine without regions. */
final class PlainEngine implements TemplateEngineInterface
{
    public function render(string $template, array $context = []): string
    {
        return '<p>' . $context['label'] . '</p>';
    }

    public function exists(string $template): bool
    {
        return true;
    }

    public function addFunction(TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }

    public function addNamespace(string $namespace, string $path): void {}
}

/** The component endpoint for components with #[State]: restore, action or refresh, render, state back. */
final class ComponentEndpointStateTest extends TestCase
{
    private const string KEY = 'endpoint-state-test-key-0123456789abcdef';

    private ComponentToken $tokens;

    private HtmxDriver $driver;

    private SignedStateStore $signed;

    private SessionStateStore $session;

    private ComponentRenderer $renderer;

    protected function setUp(): void
    {
        Hooks::$calls = [];
        Hooks::$params = null;
        $this->tokens = new ComponentToken(self::KEY);
        $this->driver = new HtmxDriver(new ResponseHeaders());
        $this->signed = new SignedStateStore(self::KEY);
        $this->session = new SessionStateStore(new ArraySession());
        $this->renderer = $this->renderer(new RegionEngine());
    }

    public function test_action_runs_on_restored_state_without_resolve(): void
    {
        $carried = $this->carried(ResolvingCounter::class, ['key' => 'a'], 2);
        $renderer = $this->renderer(new RegionEngine(), DebugLevel::Off);

        $html = $this->body($this->post(ResolvingCounter::class, 'add', ['key' => 'a'], [
            '_state' => [$this->id(ResolvingCounter::class, 'a') => $carried],
        ], $renderer));

        $this->assertSame(['add', 'prepare'], Hooks::$calls, 'no resolveState(), no mount(): the action ran on the restored state');
        $this->assertStringContainsString('<p>count 3</p>', $html);
        $this->assertStringStartsWith('<div id="' . $this->id(ResolvingCounter::class, 'a') . '"', $html, 'the whole component, wrapper and all');
    }

    public function test_region_action_returns_region_and_state_update(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        $html = $this->body($this->post(StateCounter::class, 'add', ['key' => 'a'], [
            '_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 4)],
            '_region' => 'results',
        ]));

        $this->assertStringStartsWith('<b>results 5</b>', $html, 'only the region, without its element or the wrapper');
        $this->assertStringNotContainsString('<p>count', $html);
        $this->assertSame(1, preg_match('/<input type="hidden" id="preflow-state--' . preg_quote($id, '/') . '" name="_state\[' . preg_quote($id, '/') . '\]" form="preflow-no-form" value="([^"]+)" hx-swap-oob="true">/', $html, $m), $html);
        $this->assertSame(['count' => 5], $this->signed->get(StateCounter::class, $id, html_entity_decode($m[1])), 'the new state goes back');
    }

    public function test_event_refresh_keeps_state(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        $html = $this->body($this->post(StateCounter::class, 'render', ['key' => 'a'], [
            '_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 7)],
        ]));

        $this->assertSame(['prepare'], Hooks::$calls, 'restored, then prepare(); never mount() again');
        $this->assertStringContainsString('<p>count 7</p>', $html);
        $this->assertStringContainsString('name="_state[' . $id . ']"', $html, 'the wrapper carries the state again');
    }

    public function test_tampered_state_is_refused(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        $carried = $this->carried(StateCounter::class, ['key' => 'a'], 1);
        $tampered = substr($carried, 0, -2) . (str_ends_with($carried, 'AA') ? 'BB' : 'AA');

        $this->assertRefused(fn () => $this->post(StateCounter::class, 'add', ['key' => 'a'], ['_state' => [$id => $tampered]]));
        $this->assertRefused(fn () => $this->post(StateCounter::class, 'add', ['key' => 'a'], ['_state' => [$id => ['not', 'a', 'string']]]));
        $this->assertNotContains('add', Hooks::$calls, 'the action never ran');
    }

    public function test_state_of_another_instance_is_refused(): void
    {
        $fromA = $this->carried(StateCounter::class, ['key' => 'a'], 50);

        $this->assertRefused(fn () => $this->post(StateCounter::class, 'add', ['key' => 'b'], [
            '_state' => [$this->id(StateCounter::class, 'b') => $fromA],
        ]));
        $this->assertNotContains('add', Hooks::$calls);
    }

    public function test_state_from_an_older_shape_answers_like_expired(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        // Written before a deploy renamed the property: a valid signature, a shape the class no longer has.
        $old = $this->signed->put(StateCounter::class, $id, ['counter' => 3]);

        $this->assertRefused(fn () => $this->post(StateCounter::class, 'add', ['key' => 'a'], ['_state' => [$id => $old]]));
        $this->assertNotContains('add', Hooks::$calls);
    }

    public function test_old_style_call_on_a_stateful_component(): void
    {
        // No state on the page (a template written before the component had state): mount() from the token's props.
        $html = $this->body($this->post(StateCounter::class, 'add', ['start' => 4], oldStyle: true));
        $this->assertSame(['mount', 'add', 'prepare'], Hooks::$calls);
        $this->assertStringContainsString('<p>count 5</p>', $html);

        // With state on the page, the action runs on it, whatever the props say.
        Hooks::$calls = [];
        $id = $this->id(StateCounter::class, null);
        $html = $this->body($this->post(StateCounter::class, 'add', ['start' => 4], [
            '_state' => [$id => $this->carried(StateCounter::class, [], 10)],
        ], oldStyle: true));
        $this->assertSame(['add', 'prepare'], Hooks::$calls);
        $this->assertStringContainsString('<p>count 11</p>', $html);

        // An old-style call swapped into another element: no wrapper, the state still goes back.
        Hooks::$calls = [];
        $html = $this->body($this->post(StateCounter::class, 'add', ['start' => 1], [], headers: ['HX-Request' => 'true', 'HX-Target' => 'elsewhere'], oldStyle: true));
        $this->assertSame(['mount', 'add', 'prepare'], Hooks::$calls);
        $this->assertStringStartsWith('<p>count 2</p>', $html);
        $this->assertStringContainsString('hx-swap-oob="true"', $html);
    }

    public function test_values_are_input_and_form_fields_win(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        $html = $this->body($this->post(StateCounter::class, 'echo', ['key' => 'a'], [
            '_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 8)],
            '_values' => ['q' => 'from values', 'page' => '2'],
            'q' => 'from the form',
            'count' => '99',
        ]));

        $this->assertSame(['q' => 'from the form', 'page' => '2', 'count' => '99'], Hooks::$params, 'values under form fields; no framework field');
        $this->assertStringContainsString('<p>count 8</p>', $html, 'a posted field never fills state by name');
    }

    public function test_stateful_token_carries_only_key(): void
    {
        $counter = new StateCounter();
        $counter->setProps(['start' => 3, 'key' => 'a', 'job' => ['title' => 'long']]);
        $this->assertSame(['key' => 'a'], $this->tokens->decode($this->tokens->encodeFor($counter, 'add'))->props);

        $unkeyed = new StateCounter();
        $unkeyed->setProps(['start' => 3]);
        $this->assertSame([], $this->tokens->decode($this->tokens->encodeFor($unkeyed))->props);

        $plain = new class extends Component {};
        $plain->setProps(['title' => 'x', 'key' => 'k']);
        $payload = $this->tokens->decode($this->tokens->encodeFor($plain, 'save'));
        $this->assertSame(['title' => 'x', 'key' => 'k'], $payload->props, 'without state: every prop, as before');
        $this->assertSame('save', $payload->action);
    }

    public function test_unknown_region_answers_400_without_details_outside_debug(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        $fields = ['_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 1)], '_region' => 'nope'];

        $production = $this->httpError(fn () => $this->post(StateCounter::class, 'add', ['key' => 'a'], $fields, endpointDebug: false));
        $this->assertSame(400, $production->statusCode);
        $this->assertStringNotContainsString('Counter', $production->getMessage(), 'no template or class outside debug');

        $debug = $this->httpError(fn () => $this->post(StateCounter::class, 'add', ['key' => 'a'], $fields, endpointDebug: true));
        $this->assertSame(400, $debug->statusCode);
        $this->assertStringContainsString("no region 'nope'", $debug->getMessage());
        $this->assertStringContainsString('results', $debug->getMessage());
        $this->assertNotContains('add', Hooks::$calls, 'the action never ran');
    }

    public function test_region_on_an_engine_without_regions_answers_400(): void
    {
        $renderer = $this->renderer(new PlainEngine());
        $id = $this->id(StateCounter::class, 'a');
        $error = $this->httpError(fn () => $this->post(StateCounter::class, 'add', ['key' => 'a'], [
            '_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 1)],
            '_region' => 'results',
        ], $renderer, endpointDebug: true));

        $this->assertSame(400, $error->statusCode);
        $this->assertStringContainsString('renders no regions', $error->getMessage());
    }

    public function test_a_failing_render_sends_no_state_back(): void
    {
        $id = $this->id(BrokenRegionCounter::class, 'a');
        $html = $this->body($this->post(BrokenRegionCounter::class, 'add', ['key' => 'a'], [
            '_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 1, BrokenRegionCounter::class)],
            '_region' => 'results',
        ]));
        $this->assertStringNotContainsString('_state[', $html, 'the region failed: no stale or empty state');

        $failed = $this->body($this->post(StateCounter::class, 'fail', ['key' => 'a'], [
            '_state' => [$this->id(StateCounter::class, 'a') => $this->carried(StateCounter::class, ['key' => 'a'], 1)],
        ]));
        $this->assertStringNotContainsString('_state[', $failed, 'the action failed: no state');
    }

    public function test_equal_state_renders_byte_equal_html(): void
    {
        $page = function (): string {
            $counter = new StateCounter();
            $counter->setProps(['start' => 3, 'key' => 'a']);

            return $this->renderer->render($counter);
        };

        $first = $page();
        $this->assertStringContainsString('name="_state[' . $this->id(StateCounter::class, 'a') . ']"', $first, 'the carrier is in the wrapper');
        $this->assertSame($first, $page(), 'the page cache stores and verifies one page');
    }

    public function test_session_store_through_the_endpoint(): void
    {
        $counter = new SessionCounter();
        $counter->setProps(['start' => 20, 'key' => 's']);
        $this->renderer->render($counter);
        $ref = $this->renderer->stateFor($counter);
        $this->assertIsString($ref);
        $id = $counter->getComponentId();

        Hooks::$calls = [];
        $html = $this->body($this->post(SessionCounter::class, 'add', ['key' => 's'], ['_state' => [$id => $ref]]));
        $this->assertSame(['add', 'prepare'], Hooks::$calls);
        $this->assertStringContainsString('<p>count 21</p>', $html);
        $this->assertSame(1, preg_match('/value="([^"]+)" hx-swap-oob="true">/', $html, $m));
        $this->assertNotSame($ref, $m[1], 'a new reference');
        $this->assertSame(['count' => 21], $this->session->get(SessionCounter::class, $id, $m[1]));

        $this->assertRefused(fn () => $this->post(SessionCounter::class, 'add', ['key' => 's'], ['_state' => [$id => 'unknown-reference']]));
    }

    public function test_key_only_token_without_state_is_refused(): void
    {
        $counter = new StateCounter();
        $counter->setProps(['start' => 3, 'key' => 'a']);
        $token = $this->tokens->encodeFor($counter, 'add');

        $this->assertRefused(fn () => $this->post(StateCounter::class, 'add', [], token: $token));
        $this->assertSame([], Hooks::$calls, 'never mounted from a key alone');

        // With its state, the same token works.
        $html = $this->body($this->post(StateCounter::class, 'add', [], ['_state' => [$counter->getComponentId() => $this->carried(StateCounter::class, ['key' => 'a'], 3)]], token: $token));
        $this->assertStringContainsString('<p>count 4</p>', $html);
    }

    public function test_values_as_one_json_object(): void
    {
        $id = $this->id(StateCounter::class, 'a');
        $state = ['_state' => [$id => $this->carried(StateCounter::class, ['key' => 'a'], 1)]];
        $this->body($this->post(StateCounter::class, 'echo', ['key' => 'a'], $state + ['_values' => '{"q":"v","rows":[1,2]}', '_region' => '', 'q' => 'form']));
        $this->assertSame(['q' => 'form', 'rows' => [1, 2]], Hooks::$params, 'decoded, under the form fields; an empty region is the whole component');

        try {
            $this->post(StateCounter::class, 'echo', ['key' => 'a'], $state + ['_values' => 'not json']);
            $this->fail('unreadable values were used');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->statusCode);
        }
    }

    public function test_mount_failure_on_an_action_call_is_a_mount_failure(): void
    {
        $html = $this->body($this->post(FailingMountCounter::class, 'add', ['start' => 1], oldStyle: true));

        $this->assertMatchesRegularExpression('#Phase</dt><dd[^>]*>mount</dd>#', $html, 'the boundary names the phase that failed');
        $this->assertStringNotContainsString('_state[', $html);
    }

    public function test_stateless_component_takes_values_and_region(): void
    {
        $html = $this->body($this->post(PlainCounter::class, 'echo', ['start' => 4], ['_values' => '{"q":"v","page":"2"}', '_region' => 'results', 'page' => '3']));

        $this->assertSame(['q' => 'v', 'page' => '3'], Hooks::$params, 'values under the form fields');
        $this->assertSame(['resolveState'], Hooks::$calls, 'as in 0.85: resolveState() before the action');
        $this->assertSame('<b>results 4</b>', $html, 'only the region, no state');

        Hooks::$calls = [];
        $whole = $this->body($this->post(PlainCounter::class, 'add', ['start' => 4], ['_values' => '{}', '_region' => '']));
        $this->assertStringStartsWith('<div id="' . $this->id(PlainCounter::class, null) . '"><p>plain 4</p>', $whole);
        $this->assertStringContainsString('<b>results 5</b>', $whole, 'the action ran, then the whole component rendered without resolving again');
        $this->assertSame(['resolveState'], Hooks::$calls);

        $error = $this->httpError(fn () => $this->post(PlainCounter::class, 'add', ['start' => 4], ['_region' => 'nope']));
        $this->assertSame(400, $error->statusCode);
    }

    public function test_old_style_request_on_a_stateless_component_is_unchanged(): void
    {
        $token = $this->tokens->encode(PlainCounter::class, ['start' => 2], 'echo');
        $html = $this->body($this->post(PlainCounter::class, 'echo', ['start' => 2], ['q' => 'x'], token: $token));

        $this->assertSame(['q' => 'x', 'token' => $token], Hooks::$params, 'the whole body, as in 0.85');
        $this->assertSame('<div id="' . $this->id(PlainCounter::class, null) . '"><p>plain 2</p><div id="' . $this->id(PlainCounter::class, null) . '-results"><b>results 2</b></div></div>', $html);
    }

    public function test_an_old_style_call_inside_a_short_call_element_is_handled_as_in_0_85(): void
    {
        // htmx gives the nested old-style request the outer 0.86 call's hx-vals: values and a region.
        $leaked = ['_values' => '{"q":"outer"}', '_region' => 'elsewhere'];

        $token = $this->tokens->encode(PlainCounter::class, ['start' => 2], 'echo');
        $html = $this->body($this->post(PlainCounter::class, 'echo', ['start' => 2], $leaked + ['q' => 'x'], token: $token));
        $this->assertSame(['q' => 'x', 'token' => $token], Hooks::$params, 'params as in 0.85, nothing of the outer call');
        $this->assertStringStartsWith('<div id="' . $this->id(PlainCounter::class, null) . '"><p>plain 2</p>', $html, 'the whole component, no region');

        // The same for a component with state called the old way.
        Hooks::$params = null;
        $html = $this->body($this->post(StateCounter::class, 'echo', ['start' => 4], $leaked + ['q' => 'x'], oldStyle: true));
        $this->assertSame(['q' => 'x'], Hooks::$params);
        $this->assertStringContainsString('<p>count 4</p>', $html);
    }

    public function test_an_answer_with_a_short_call_brings_the_busy_marker(): void
    {
        $collector = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
        $driver = new HtmxDriver(new ResponseHeaders());
        $driver->useAssets(new \Preflow\Htmx\HtmxAssets($collector, 'on-demand'));
        // A template that writes a 0.86 call, as hd.post() will.
        $engine = new class ($driver) implements TemplateEngineInterface {
            public function __construct(private readonly HtmxDriver $driver) {}

            public function render(string $template, array $context = []): string
            {
                $call = new \Preflow\Core\Http\Hypermedia\ActionCall('post', '/a', $context['componentId'], $context['componentId'], \Preflow\Core\Http\Hypermedia\ActionOptions::from([], true));

                return '<button ' . (string) new \Preflow\Htmx\HtmlAttributes($this->driver->callAttributes($call)) . '>+</button>';
            }

            public function exists(string $template): bool
            {
                return true;
            }

            public function addFunction(TemplateFunctionDefinition $function): void {}

            public function addGlobal(string $name, mixed $value): void {}

            public function getTemplateExtension(): string
            {
                return 'twig';
            }

            public function addNamespace(string $namespace, string $path): void {}
        };
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::On));
        $endpoint = new ComponentEndpoint(
            token: $this->tokens,
            renderer: $renderer,
            driver: $driver,
            componentFactory: static fn (string $class, array $props): Component => new $class(),
            assetCollector: $collector,
        );
        $html = (string) $endpoint->handle((new Psr17Factory())->createServerRequest('POST', '/--component/action')
            ->withParsedBody(['token' => $this->tokens->encode(PlainCounter::class, [], 'render')])->withHeader('HX-Request', 'true'))->getBody();

        $this->assertStringContainsString('hx-post="/a"', $html);
        $this->assertStringContainsString("preflowBusyMarker('preflow-busy')", $html, 'the first short call of a page may arrive in an answer');
    }

    /** @param array<string, mixed> $props */
    private function carried(string $class, array $props, int $count, ?string $as = null): string
    {
        $component = new ($as ?? $class)();
        $component->setProps($props);

        return $this->signed->put($as ?? $class, $component->getComponentId(), ['count' => $count]);
    }

    private function id(string $class, ?string $key): string
    {
        $component = new $class();
        $component->setProps($key === null ? [] : ['key' => $key]);

        return $component->getComponentId();
    }

    private function renderer(TemplateEngineInterface $engine, DebugLevel $debug = DebugLevel::On): ComponentRenderer
    {
        $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: $debug));
        $renderer->enableState(
            fn (string $store): StateStore => $store === SessionStateStore::class ? $this->session : $this->signed,
            $debug->isDebug(),
        );
        $renderer->carryStateWith($this->driver->stateCarrier(...));

        return $renderer;
    }

    /**
     * @param array<string, mixed> $props the token's props
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function post(
        string $class,
        string $action,
        array $props,
        array $body = [],
        ?ComponentRenderer $renderer = null,
        bool $endpointDebug = false,
        array $headers = ['HX-Request' => 'true'],
        ?string $token = null,
        bool $oldStyle = false,
    ): ResponseInterface {
        if ($token === null) {
            $made = new $class();
            $made->setProps($props);
            // A 0.86 call's token (encodeFor()), or an old-style one with all props (encode()).
            $token = $oldStyle ? $this->tokens->encode($class, $props, $action) : $this->tokens->encodeFor($made, $action);
        }
        $endpoint = new ComponentEndpoint(
            token: $this->tokens,
            renderer: $renderer ?? $this->renderer,
            driver: $this->driver,
            componentFactory: static function (string $class, array $props): Component {
                $component = new $class();
                $component->setProps($props);

                return $component;
            },
            debug: $endpointDebug,
        );
        $request = (new Psr17Factory())->createServerRequest('POST', '/--component/action')
            ->withParsedBody($body + ['token' => $token]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $endpoint->handle($request);
    }

    private function body(ResponseInterface $response): string
    {
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function assertRefused(\Closure $call): void
    {
        try {
            $call();
            $this->fail('the state was used');
        } catch (StateRefused $e) {
            $this->assertInstanceOf(ForbiddenHttpException::class, $e);
            $this->assertSame(403, $e->statusCode, 'answers like a refused token');
        }
    }

    private function httpError(\Closure $call): HttpException
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('no HTTP error');
    }
}
