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
        $this->assertSame(1, preg_match('/<input type="hidden" id="' . preg_quote($id, '/') . '--state" name="_state\[' . preg_quote($id, '/') . '\]" form="preflow-no-form" value="([^"]+)" hx-swap-oob="true">/', $html, $m), $html);
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
        $html = $this->body($this->post(StateCounter::class, 'add', ['start' => 4]));
        $this->assertSame(['mount', 'add', 'prepare'], Hooks::$calls);
        $this->assertStringContainsString('<p>count 5</p>', $html);

        // With state on the page, the action runs on it, whatever the props say.
        Hooks::$calls = [];
        $id = $this->id(StateCounter::class, null);
        $html = $this->body($this->post(StateCounter::class, 'add', ['start' => 4], [
            '_state' => [$id => $this->carried(StateCounter::class, [], 10)],
        ]));
        $this->assertSame(['add', 'prepare'], Hooks::$calls);
        $this->assertStringContainsString('<p>count 11</p>', $html);

        // An old-style call swapped into another element: no wrapper, the state still goes back.
        Hooks::$calls = [];
        $html = $this->body($this->post(StateCounter::class, 'add', ['start' => 1], [], headers: ['HX-Request' => 'true', 'HX-Target' => 'elsewhere']));
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
    ): ResponseInterface {
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
            ->withParsedBody($body + ['token' => $this->tokens->encode($class, $props, $action)]);
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
