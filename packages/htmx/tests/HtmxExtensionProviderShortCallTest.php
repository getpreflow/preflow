<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests\ShortCall;

use PHPUnit\Framework\TestCase;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\ErrorBoundary;
use Preflow\Components\SignedStateStore;
use Preflow\Components\State;
use Preflow\Components\StateStore;
use Preflow\Core\DebugLevel;
use Preflow\Htmx\ComponentToken;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\HtmxExtensionProvider;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\LazyHypermediaDriver;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\SwapStrategy;
use Preflow\View\SafeHtml;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Renders each template with the callback registered under its name. */
final class CallbackEngine implements TemplateEngineInterface
{
    /** @var array<string, \Closure(array<string, mixed>): string> */
    public static array $templates = [];

    public function render(string $template, array $context = []): string
    {
        return (self::$templates[$template])($context);
    }

    public function exists(string $template): bool
    {
        return isset(self::$templates[$template]);
    }

    public function addFunction(TemplateFunctionDefinition $function): void {}

    public function addGlobal(string $name, mixed $value): void {}

    public function getTemplateExtension(): string
    {
        return 'twig';
    }

    public function addNamespace(string $namespace, string $path): void {}
}

/** Without #[State]: its token carries all its props. */
final class ShortBox extends Component
{
    public function actions(): array
    {
        return ['add', 'remove'];
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'ShortBox';
    }
}

/** With #[State]: its token carries only its key, its state travels in the carrier. */
final class ShortCounter extends Component
{
    #[State] public int $count = 0;

    public function mount(): void
    {
        $this->count = (int) ($this->props['start'] ?? 0);
    }

    public function actions(): array
    {
        return ['add'];
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'ShortCounter';
    }
}

/** With #[State]; renders a ShortPager inside. */
final class ShortSearch extends Component
{
    #[State] public int $page = 1;

    public function actions(): array
    {
        return ['search'];
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'ShortSearch';
    }
}

/** Calls its parent's action. */
final class ShortPager extends Component
{
    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'ShortPager';
    }
}

/** A row with its own action; its template also calls its parent's action. */
final class ShortRow extends Component
{
    public function actions(): array
    {
        return ['toggle'];
    }

    public function actionToggle(array $params = []): void {}

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'ShortRow';
    }
}

/** Without #[State]; renders a ShortRow inside. */
final class ShortList extends Component
{
    public function actions(): array
    {
        return ['reload'];
    }

    public function getTemplatePath(string $extension = 'twig'): string
    {
        return 'ShortList';
    }
}

/** A driver that knows only the old calls (no DrivesComponentActions). */
final class OldCallsDriver implements HypermediaDriver
{
    public function actionAttrs(string $method, string $url, string $targetId, SwapStrategy $swap, array $extra = []): HtmlAttributes
    {
        return new HtmlAttributes(['data-' . $method => $url]);
    }

    public function listenAttrs(string $event, string $url, string $targetId): HtmlAttributes
    {
        return new HtmlAttributes(['data-on-' . $event => $url]);
    }

    public function triggerEvent(string $event): void {}

    public function redirect(string $url): void {}

    public function pushUrl(string $url): void {}

    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return false;
    }

    public function fragmentTarget(ServerRequestInterface $request): ?string
    {
        return null;
    }

    public function applyEffects(ResponseInterface $response): ResponseInterface
    {
        return $response;
    }

    public function assetTag(): string
    {
        return '';
    }

    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return false;
    }

    public function varyHeaders(): array
    {
        return [];
    }

    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface
    {
        return $response;
    }

    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface
    {
        return $response;
    }

    public function nonceHeaders(): array
    {
        return [];
    }

    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return null;
    }
}

/** Drives component actions and keeps the calls it was given (attributes from HtmxDriver). */
final class RecordingCallsDriver implements HypermediaDriver, \Preflow\Core\Http\Hypermedia\DrivesComponentActions
{
    /** @var list<\Preflow\Core\Http\Hypermedia\ActionCall> */
    public array $calls = [];

    private HtmxDriver $htmx;

    public function __construct()
    {
        $this->htmx = new HtmxDriver(new ResponseHeaders(), true);
    }

    public function callAttributes(\Preflow\Core\Http\Hypermedia\ActionCall $call): array
    {
        $this->calls[] = $call;

        return $this->htmx->callAttributes($call);
    }

    public function listenAttributes(\Preflow\Core\Http\Hypermedia\ActionCall $call, string $event): array
    {
        $this->calls[] = $call;

        return $this->htmx->listenAttributes($call, $event);
    }

    public function stateCarrier(string $componentId, string $carried): string
    {
        return $this->htmx->stateCarrier($componentId, $carried);
    }

    public function stateUpdate(string $componentId, string $carried): string
    {
        return $this->htmx->stateUpdate($componentId, $carried);
    }

    /** @param array<string, string> $extra */
    public function actionAttrs(string $method, string $url, string $targetId, SwapStrategy $swap, array $extra = []): HtmlAttributes
    {
        return $this->htmx->actionAttrs($method, $url, $targetId, $swap, $extra);
    }

    public function listenAttrs(string $event, string $url, string $targetId): HtmlAttributes
    {
        return $this->htmx->listenAttrs($event, $url, $targetId);
    }

    public function triggerEvent(string $event): void {}

    public function redirect(string $url): void {}

    public function pushUrl(string $url): void {}

    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return false;
    }

    public function fragmentTarget(ServerRequestInterface $request): ?string
    {
        return null;
    }

    public function applyEffects(ResponseInterface $response): ResponseInterface
    {
        return $response;
    }

    public function assetTag(): string
    {
        return '';
    }

    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return false;
    }

    public function varyHeaders(): array
    {
        return [];
    }

    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface
    {
        return $response;
    }

    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface
    {
        return $response;
    }

    public function nonceHeaders(): array
    {
        return [];
    }

    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return null;
    }
}

/** A token with a fixed issue time, so the old form's output can be compared byte for byte. */
final class FixedTimeToken extends ComponentToken
{
    protected function currentTime(): int
    {
        return 1767225600;
    }
}

/** hd.post/get/on without class and id (the component whose template renders), and hd.region. */
final class HtmxExtensionProviderShortCallTest extends TestCase
{
    private const string KEY = 'short-call-test-key-0123456789abcdef';

    private ComponentToken $tokens;

    private ComponentRenderer $renderer;

    private HtmxExtensionProvider $hd;

    /** @var list<\Throwable> what the error boundary caught */
    private array $errors = [];

    protected function setUp(): void
    {
        CallbackEngine::$templates = [];
        $this->errors = [];
        $this->tokens = new ComponentToken(self::KEY);
        $driver = new HtmxDriver(new ResponseHeaders(), true);
        $this->renderer = $this->renderer($driver);
        $this->hd = new HtmxExtensionProvider($driver, $this->tokens, renderer: $this->renderer, debug: true);
    }

    public function test_short_post_infers_the_rendering_component(): void
    {
        $seen = null;
        CallbackEngine::$templates['ShortBox'] = function () use (&$seen): string {
            $seen = $this->hd->post('add', ['row' => 3]);

            return '<button ' . $seen . '>Add</button>';
        };
        $box = $this->component(ShortBox::class, ['key' => 'a', 'title' => 'Hello']);
        $this->renderer->render($box);

        $this->assertInstanceOf(SafeHtml::class, $seen);
        $attributes = self::attributes((string) $seen);
        $payload = $this->payload($attributes['hx-post']);
        $this->assertSame(ShortBox::class, $payload->componentClass);
        $this->assertSame('add', $payload->action);
        $this->assertSame(['key' => 'a', 'title' => 'Hello'], $payload->props, 'a component without state: all its props');
        $this->assertTrue($payload->neutralCall, 'a 0.86 token (encodeFor())');
        $this->assertSame('#' . $box->getComponentId(), $attributes['hx-target']);
        $this->assertSame(['_values' => '{"row":3}', '_region' => ''], json_decode($attributes['hx-vals'], true));
        $this->assertSame('unset', $attributes['hx-include'], 'a component without state renders no carrier, so the call names none (htmx would log "returned no matches")');
        $this->assertStringNotContainsString('preflow-state--', (string) $seen);

        // A component with state: a key-only token, and its carrier goes with the call.
        CallbackEngine::$templates['ShortCounter'] = function () use (&$seen): string {
            $seen = $this->hd->get('add', [], ['target' => 'results']);

            return '<button ' . $seen . '>+</button>';
        };
        $counter = $this->component(ShortCounter::class, ['key' => 'c', 'start' => 5]);
        $this->renderer->render($counter);
        $attributes = self::attributes((string) $seen);
        $payload = $this->payload($attributes['hx-get']);
        $this->assertSame(['key' => 'c'], $payload->props);
        $this->assertTrue($payload->stateCarried);
        $this->assertStringContainsString('#preflow-state--' . $counter->getComponentId(), $attributes['hx-include']);
        $this->assertSame('#' . $counter->getComponentId() . '-results', $attributes['hx-target'], 'a region target: the region element of the component');
        $this->assertSame('innerHTML', $attributes['hx-swap']);
        $this->assertSame([], $this->errors);
    }

    public function test_parent_call_targets_the_parent(): void
    {
        $pager = $this->component(ShortPager::class, []);
        $seen = null;
        CallbackEngine::$templates['ShortSearch'] = fn (): string => '<div>' . $this->renderer->render($pager) . '</div>';
        CallbackEngine::$templates['ShortPager'] = function () use (&$seen): string {
            $seen = $this->hd->post('search', ['page' => 2], ['component' => 'parent', 'scroll' => 'top']);

            return '<a ' . $seen . '>2</a>';
        };
        $search = $this->component(ShortSearch::class, ['key' => 's']);
        $this->renderer->render($search);

        $this->assertSame([], $this->errors);
        $attributes = self::attributes((string) $seen);
        $payload = $this->payload($attributes['hx-post']);
        $parentId = $search->getComponentId();
        $this->assertSame(ShortSearch::class, $payload->componentClass, "the parent's action");
        $this->assertSame('search', $payload->action);
        $this->assertTrue($payload->stateCarried);
        $this->assertSame('#' . $parentId, $attributes['hx-target'], 'the answer replaces the parent');
        $this->assertStringContainsString('#preflow-state--' . $parentId, $attributes['hx-include'], "the parent's state goes with it");
        $this->assertStringNotContainsString($pager->getComponentId(), $attributes['hx-include'] . $attributes['hx-sync']);
        $this->assertStringStartsWith('#' . $parentId . ':', $attributes['hx-sync'], "queued with the parent's other actions");
    }

    public function test_parent_call_works_in_the_answer_of_the_childs_own_action(): void
    {
        $calls = [];
        CallbackEngine::$templates['ShortRow'] = function () use (&$calls): string {
            $calls = [
                'own' => (string) $this->hd->post('toggle'),
                'refresh' => (string) $this->hd->on('saved'),
                'parent' => (string) $this->hd->post('search', ['page' => 2], ['component' => 'parent', 'target' => 'results']),
            ];

            return '<button ' . $calls['own'] . '>t</button><a ' . $calls['parent'] . '>2</a>';
        };
        CallbackEngine::$templates['ShortSearch'] = fn (): string => $this->renderer->render($this->component(ShortRow::class, ['key' => 'r1']));
        $search = $this->component(ShortSearch::class, ['key' => 's']);
        $parentId = $search->getComponentId();
        $this->renderer->render($search);
        $this->assertSame([], $this->errors);

        // The row's own tokens name its parent, for when the endpoint renders the row alone.
        $own = $this->payload(self::attributes($calls['own'])['hx-post']);
        $this->assertSame(ShortRow::class, $own->componentClass);
        $this->assertSame(ShortSearch::class, $own->parentClass);
        $this->assertSame('s', $own->parentKey);
        $this->assertSame(ShortSearch::class, $this->payload(self::attributes($calls['refresh'])['hx-post'])->parentClass);

        // The row's action and its refresh, each in a new request: the endpoint renders the row alone.
        $urls = ['own' => self::attributes($calls['own'])['hx-post'], 'refresh' => self::attributes($calls['refresh'])['hx-post']];
        foreach ($urls as $which => $url) {
            $calls['parent'] = '';
            $this->freshRequest();
            $response = $this->endpoint()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
                ->createServerRequest('POST', (string) parse_url($url, PHP_URL_PATH))
                ->withHeader('HX-Request', 'true')
                ->withParsedBody(['token' => $this->token($url)]));
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame([], $this->errors, "{$which}: the parent call in the row's answer works");

            $attributes = self::attributes($calls['parent']);
            $payload = $this->payload($attributes['hx-post']);
            $this->assertSame(ShortSearch::class, $payload->componentClass, "{$which}: the parent's action");
            $this->assertSame('search', $payload->action);
            $this->assertSame(['key' => 's'], $payload->props, "{$which}: the parent's key");
            $this->assertTrue($payload->stateCarried, "{$which}: the parent's state travels beside the token");
            $this->assertSame('#' . $parentId . '-results', $attributes['hx-target'], "{$which}: the parent on the page");
            $this->assertStringContainsString('#preflow-state--' . $parentId, $attributes['hx-include'], "{$which}: the parent's carrier, already on the page, goes with it");
            $this->assertStringContainsString('<button ', (string) $response->getBody());
        }
    }

    public function test_parent_call_from_a_child_rendered_alone_needs_a_parent_with_state(): void
    {
        $own = '';
        CallbackEngine::$templates['ShortRow'] = function () use (&$own): string {
            $own = (string) $this->hd->post('toggle');

            return '<a ' . $this->hd->post('reload', [], ['component' => 'parent']) . '>r</a>';
        };
        CallbackEngine::$templates['ShortList'] = fn (): string => $this->renderer->render($this->component(ShortRow::class, ['key' => 'r1']));
        $this->renderer->render($this->component(ShortList::class, ['key' => 'l', 'filter' => 'open']));
        $this->assertSame([], $this->errors, 'on the page the parent is known: all its props');

        $this->freshRequest();
        $this->endpoint()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/--component/action')
            ->withHeader('HX-Request', 'true')
            ->withParsedBody(['token' => $this->token(self::attributes($own)['hx-post'])]));

        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\LogicException::class, $this->errors[0]);
        $this->assertStringContainsString('a child rendered alone can call its parent only when the parent keeps state (#[State])', $this->errors[0]->getMessage());
        $this->assertStringContainsString(ShortList::class, $this->errors[0]->getMessage());
    }

    public function test_parent_call_without_a_parent_fails(): void
    {
        CallbackEngine::$templates['ShortBox'] = fn (): string => (string) $this->hd->post('add', [], ['component' => 'parent']);
        $this->renderer->render($this->component(ShortBox::class, []));

        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\LogicException::class, $this->errors[0]);
        $this->assertStringContainsString(ShortBox::class, $this->errors[0]->getMessage());
        $this->assertStringContainsString('parent', $this->errors[0]->getMessage());
    }

    public function test_has_parent(): void
    {
        $seen = [];
        CallbackEngine::$templates['ShortSearch'] = function () use (&$seen): string {
            $seen['search'] = $this->hd->hasParent();

            return $this->renderer->render($this->component(ShortPager::class, ['key' => 'p']));
        };
        CallbackEngine::$templates['ShortPager'] = function () use (&$seen): string {
            $seen['pager'] = $this->hd->hasParent();

            return '';
        };
        $this->renderer->render($this->component(ShortSearch::class, ['key' => 's']));
        $this->assertSame([], $this->errors);
        $this->assertSame(['search' => false, 'pager' => true], $seen, 'top level: no parent; inside the parent\'s template: one');
    }

    public function test_has_parent_in_a_child_rendered_alone_for_its_own_action(): void
    {
        $own = '';
        $seen = [];
        CallbackEngine::$templates['ShortRow'] = function () use (&$own, &$seen): string {
            $own = (string) $this->hd->post('toggle');
            $seen[] = $this->hd->hasParent();

            return '';
        };
        CallbackEngine::$templates['ShortSearch'] = fn (): string => $this->renderer->render($this->component(ShortRow::class, ['key' => 'r1']));
        $this->renderer->render($this->component(ShortSearch::class, ['key' => 's']));

        // The token names the parent (pc/pk): the endpoint renders the row alone, still with a parent.
        $this->freshRequest();
        $this->endpoint()->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/--component/action')
            ->withHeader('HX-Request', 'true')
            ->withParsedBody(['token' => $this->token(self::attributes($own)['hx-post'])]));
        $this->assertSame([], $this->errors);
        $this->assertSame([true, true], $seen);

        // Rendered alone without a parent in its token (e.g. a preview): none.
        $this->freshRequest();
        $seen = [];
        $this->renderer->render($this->component(ShortRow::class, ['key' => 'r2']));
        $this->assertSame([false], $seen);
    }

    public function test_the_parent_is_built_only_when_asked(): void
    {
        $own = '';
        $seen = [];
        CallbackEngine::$templates['ShortRow'] = function () use (&$own, &$seen): string {
            $own = (string) $this->hd->post('toggle');
            $seen[] = $this->hd->hasParent();

            return '<button ' . $own . '>t</button>';
        };
        CallbackEngine::$templates['ShortSearch'] = fn (): string => $this->renderer->render($this->component(ShortRow::class, ['key' => 'r1']));
        $this->renderer->render($this->component(ShortSearch::class, ['key' => 's']));

        // The parent's class can't be built here (say its constructor needs a service this request lacks).
        $this->freshRequest();
        $seen = [];
        $built = [];
        $response = $this->endpoint(function (string $class, array $props) use (&$built): Component {
            $built[] = $class;
            if ($class === ShortSearch::class) {
                throw new \RuntimeException('the parent cannot be built here');
            }

            return $this->component($class, $props);
        })->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/--component/action')
            ->withHeader('HX-Request', 'true')
            ->withParsedBody(['token' => $this->token(self::attributes($own)['hx-post'])]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->errors, 'nothing asked for the parent itself');
        $this->assertSame([ShortRow::class], $built, 'only the child was built');
        $this->assertSame([true], $seen, 'hd.hasParent() answers without the parent');
        // The answer's own call still names the parent on the page, for the next action.
        $payload = $this->payload(self::attributes($own)['hx-post']);
        $this->assertSame(ShortSearch::class, $payload->parentClass);
        $this->assertSame('s', $payload->parentKey);
    }

    public function test_a_parent_call_builds_the_parent_once(): void
    {
        $own = '';
        $parent = [];
        CallbackEngine::$templates['ShortRow'] = function () use (&$own, &$parent): string {
            $own = (string) $this->hd->post('toggle');
            $parent[] = (string) $this->hd->post('search', [], ['component' => 'parent']);
            $parent[] = (string) $this->hd->get('search', [], ['component' => 'parent']);

            return '';
        };
        CallbackEngine::$templates['ShortSearch'] = fn (): string => $this->renderer->render($this->component(ShortRow::class, ['key' => 'r1']));
        $search = $this->component(ShortSearch::class, ['key' => 's']);
        $this->renderer->render($search);

        $this->freshRequest();
        $parent = [];
        $built = [];
        $this->endpoint(function (string $class, array $props) use (&$built): Component {
            $built[] = $class;

            return $this->component($class, $props);
        })->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/--component/action')
            ->withHeader('HX-Request', 'true')
            ->withParsedBody(['token' => $this->token(self::attributes($own)['hx-post'])]));

        $this->assertSame([], $this->errors);
        $this->assertSame([ShortRow::class, ShortSearch::class], $built, 'the parent built once, for the first call that needs it');
        $this->assertSame('#' . $search->getComponentId(), self::attributes($parent[0])['hx-target'], 'the parent on the page');
        $this->assertSame('#' . $search->getComponentId(), self::attributes($parent[1])['hx-target']);
    }

    public function test_a_token_naming_a_class_that_is_no_component_as_parent_is_refused(): void
    {
        $token = $this->tokens->encodeFor($this->component(ShortRow::class, ['key' => 'r1']), 'toggle', new \Preflow\Components\ParentRef(\stdClass::class));

        $this->expectException(\Preflow\Core\Exceptions\SecurityException::class);
        $this->expectExceptionMessage('Invalid parent component class [stdClass].');
        $this->endpoint(function (string $class, array $props): Component {
            $this->assertNotSame(\stdClass::class, $class, 'refused before anything is built');

            return $this->component($class, $props);
        })->handle((new \Nyholm\Psr7\Factory\Psr17Factory())
            ->createServerRequest('POST', '/--component/action')
            ->withHeader('HX-Request', 'true')
            ->withParsedBody(['token' => $token]));
    }

    public function test_has_parent_in_twig_and_blade(): void
    {
        $dir = sys_get_temp_dir() . '/preflow_has_parent_' . bin2hex(random_bytes(4));
        mkdir($dir . '/cache', 0777, true);
        try {
            file_put_contents($dir . '/ShortBox', "{% if hd.hasParent() %}<a {{ hd.post('search', {}, {component: 'parent'}) }}>p</a>{% else %}alone{% endif %}");
            file_put_contents($dir . '/ShortBox.blade.php', "@if(\$hd->hasParent())<a {{ \$hd->post('search', [], ['component' => 'parent']) }}>p</a>@else alone @endif");
            $engines = [
                'twig' => new \Preflow\Twig\TwigEngine([$dir], new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator()), debug: true),
                'blade' => new \Preflow\Blade\BladeEngine([$dir], new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator()), cachePath: $dir . '/cache'),
            ];
            $html = [];
            foreach ($engines as $name => $engine) {
                $driver = new HtmxDriver(new ResponseHeaders(), true);
                $renderer = new ComponentRenderer($engine, new ErrorBoundary(debug: DebugLevel::On, onError: function (\Throwable $e): void {
                    $this->errors[] = $e;
                }));
                $engine->addGlobal('hd', new HtmxExtensionProvider($driver, $this->tokens, renderer: $renderer, debug: true));

                $html[$name]['alone'] = $renderer->render($this->component(ShortBox::class, ['key' => 'a']));
                $placed = $this->component(ShortBox::class, ['key' => 'b']);
                $renderer->placeInside($placed, $this->component(ShortSearch::class, ['key' => 's']));
                $html[$name]['placed'] = $renderer->render($placed);
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame([], $this->errors);
        foreach ($html as $name => $rendered) {
            $this->assertStringContainsString('alone', $rendered['alone'], $name);
            $this->assertStringNotContainsString('hx-post', $rendered['alone'], $name);
            $this->assertStringContainsString('<a hx-post="/--component/action?token=', $rendered['placed'], $name);
        }
    }

    public function test_target_parent_is_refused(): void
    {
        $pager = $this->component(ShortPager::class, []);
        CallbackEngine::$templates['ShortSearch'] = fn (): string => $this->renderer->render($pager);
        CallbackEngine::$templates['ShortPager'] = fn (): string => (string) $this->hd->post('next', [], ['target' => 'parent']);
        $this->renderer->render($this->component(ShortSearch::class, ['key' => 's']));

        // In debug: refused, pointing at component: 'parent'.
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\LogicException::class, $this->errors[0]);
        $this->assertStringContainsString("hd.post('next') with target: 'parent'", $this->errors[0]->getMessage());
        $this->assertStringContainsString("to run the parent's action use component: 'parent'", $this->errors[0]->getMessage());

        // Outside debug: a log line, and the call targets its own component (never the child's answer over the parent).
        $logged = [];
        $hd = new HtmxExtensionProvider(new HtmxDriver(new ResponseHeaders()), $this->tokens, renderer: $this->renderer, log: static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $this->errors = [];
        $seen = null;
        CallbackEngine::$templates['ShortPager'] = static function () use ($hd, &$seen): string {
            $seen = (string) $hd->post('next', [], ['target' => 'parent']);

            return '';
        };
        $this->renderer->render($this->component(ShortSearch::class, ['key' => 's']));
        $this->assertSame([], $this->errors);
        $this->assertCount(1, $logged);
        $this->assertStringContainsString("component: 'parent'", $logged[0]);
        $attributes = self::attributes((string) $seen);
        $this->assertSame('#' . $pager->getComponentId(), $attributes['hx-target']);
        $this->assertSame(ShortPager::class, $this->payload($attributes['hx-post'])->componentClass);
    }

    public function test_short_call_needs_no_escaping_in_blade(): void
    {
        $dir = sys_get_temp_dir() . '/preflow_short_call_blade_' . bin2hex(random_bytes(4));
        mkdir($dir . '/cache', 0777, true);
        try {
            file_put_contents($dir . '/ShortBox.blade.php', "<button {{ \$hd->post('add', ['row' => 1]) }}>a</button><ul {{ \$hd->region('rows') }}></ul>"
                . "<i {{ \$hd->post('add', \$componentClass, \$componentId) }}></i><b {!! \$hd->post('add', \$componentClass, \$componentId) !!}></b>");
            $blade = new \Preflow\Blade\BladeEngine([$dir], new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator()), cachePath: $dir . '/cache');
            $driver = new HtmxDriver(new ResponseHeaders(), true);
            $renderer = new ComponentRenderer($blade, new ErrorBoundary(debug: DebugLevel::On, onError: function (\Throwable $e): void {
                $this->errors[] = $e;
            }));
            $blade->addGlobal('hd', new HtmxExtensionProvider($driver, $this->tokens, renderer: $renderer, debug: true));

            $box = $this->component(ShortBox::class, ['key' => 'a']);
            $html = $renderer->render($box);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame([], $this->errors);
        $this->assertStringContainsString('<button hx-post="/--component/action?token=', $html, 'printed as markup by {{ }}');
        $this->assertStringContainsString('hx-vals="{&quot;_values&quot;:&quot;{\\&quot;row\\&quot;:1}&quot;', $html, 'escaped once, by the helper');
        $this->assertStringContainsString('<ul id="' . $box->getComponentId() . '-rows"></ul>', $html);
        $this->assertStringContainsString('<i hx-post=&quot;', $html, 'the old form still returns text, escaped by {{ }}');
        $this->assertStringContainsString('<b hx-post="/--component/action?token=', $html, 'the old form with {!! !!} as before');
    }

    public function test_short_call_needs_no_raw(): void
    {
        $dir = sys_get_temp_dir() . '/preflow_short_call_' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            file_put_contents($dir . '/ShortBox', "<button {{ hd.post('add', {row: 1}) }}>a</button><ul {{ hd.region('rows') }}></ul>"
                . "<i {{ hd.post('add', componentClass, componentId) }}></i><b {{ hd.post('add', componentClass, componentId) | raw }}></b>");
            $twig = new \Preflow\Twig\TwigEngine([$dir], new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator()), debug: true);
            $driver = new HtmxDriver(new ResponseHeaders(), true);
            $renderer = new ComponentRenderer($twig, new ErrorBoundary(debug: DebugLevel::On, onError: function (\Throwable $e): void {
                $this->errors[] = $e;
            }));
            $twig->addGlobal('hd', new HtmxExtensionProvider($driver, $this->tokens, renderer: $renderer, debug: true));

            $box = $this->component(ShortBox::class, ['key' => 'a']);
            $html = $renderer->render($box);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame([], $this->errors);
        $this->assertStringContainsString('<button hx-post="/--component/action?token=', $html, 'printed as markup, without | raw');
        $this->assertStringContainsString('hx-vals="{&quot;_values&quot;:&quot;{\&quot;row\&quot;:1}&quot;', $html, 'escaped once, by the helper');
        $this->assertStringContainsString('<ul id="' . $box->getComponentId() . '-rows"></ul>', $html);
        $this->assertStringContainsString('<i hx-post=&quot;', $html, 'the old form still returns text, escaped without | raw');
        $this->assertStringContainsString('<b hx-post="/--component/action?token=', $html, 'the old form with | raw as before');
    }

    public function test_one_token_per_action_not_per_button(): void
    {
        $buttons = [];
        CallbackEngine::$templates['ShortCounter'] = function () use (&$buttons): string {
            foreach ([1, 2] as $row) {
                $buttons[] = self::attributes((string) $this->hd->post('add', ['row' => $row]));
            }

            return '';
        };
        $this->renderer->render($this->component(ShortCounter::class, ['key' => 'c']));

        $this->assertSame($buttons[0]['hx-post'], $buttons[1]['hx-post'], 'one token for the action, whatever the values');
        $this->assertNotSame($buttons[0]['hx-vals'], $buttons[1]['hx-vals'], 'the values differ, unsigned beside it');
    }

    public function test_old_form_is_unchanged(): void
    {
        // Captured from the 0.85 helpers (HtmxExtensionProvider before the short call), same key and time.
        $expected = [
            'post' => 'hx-post="/de/--component/action?token=eyJjIjoiQXBwXFxDb21wb25lbnRzXFxDb3VudGVyIiwicCI6eyJpZCI6NywidGFnIjoiYSZiIn0sImEiOiJzYXZlIiwidCI6MTc2NzIyNTYwMH0uNWE1NmUxMmY1M2M1MjhiMDI5YzVmNWM0Njg2ZjM3MzNkNzg5OTEwYmZkNzg2NDNhNjRmZTk2MmQ3NWZiMDFmNA" hx-target="#Counter-abc123" hx-swap="innerHTML" hx-confirm="Sure? &quot;yes&quot;"',
            'get' => 'hx-get="/de/--component/action?token=eyJjIjoiQXBwXFxDb21wb25lbnRzXFxDb3VudGVyIiwicCI6eyJpZCI6N30sImEiOiJtb3JlIiwidCI6MTc2NzIyNTYwMH0uNGEzNDYxNDdiZTIzZGQ4M2NhMDIxNTBmNzk4M2FjZTY3NTRjOWVkN2NhZWEzMzExZDJkNzM0Njc4NGM3NWUwMw" hx-target="#Counter-abc123" hx-swap="outerHTML"',
            'on' => 'hx-trigger="cartUpdated from:body" hx-get="/de/--component/render?token=eyJjIjoiQXBwXFxDb21wb25lbnRzXFxDb3VudGVyIiwicCI6eyJpZCI6N30sImEiOiJyZW5kZXIiLCJ0IjoxNzY3MjI1NjAwfS44YTFhNTdjZjhmNzA2MTNmYTM0MDI5MWM1MjkwMDRhMzFhNjg3Y2IxNGNiYWYxNTRmNDE1ZGE5YzI0OGI2N2Yy" hx-target="#Counter-abc123" hx-swap="outerHTML"',
        ];
        $class = 'App\\Components\\Counter';
        $calls = static fn (HtmxExtensionProvider $hd): array => [
            'post' => $hd->post('save', $class, 'Counter-abc123', ['id' => 7, 'tag' => 'a&b'], SwapStrategy::InnerHTML, ['hx-confirm' => 'Sure? "yes"']),
            'get' => $hd->get('more', $class, 'Counter-abc123', ['id' => 7]),
            'on' => $hd->on('cartUpdated', $class, 'Counter-abc123', ['id' => 7]),
        ];

        $without = new HtmxExtensionProvider(new HtmxDriver(new ResponseHeaders()), new FixedTimeToken('old-form-fixture-key-0123456789abcdef'), pathPrefix: fn (): string => '/de');
        $this->assertSame($expected, $calls($without), 'as in 0.85, plain text');

        // Inside a component's render, with a renderer and debug: still the old form.
        $with = new HtmxExtensionProvider(new HtmxDriver(new ResponseHeaders(), true), new FixedTimeToken('old-form-fixture-key-0123456789abcdef'), pathPrefix: fn (): string => '/de', renderer: $this->renderer, debug: true);
        $inside = null;
        CallbackEngine::$templates['ShortBox'] = function () use (&$inside, $calls, $with): string {
            $inside = $calls($with);

            return '';
        };
        $this->renderer->render($this->component(ShortBox::class, []));
        $this->assertSame($expected, $inside);

        // Named arguments, as a Blade template may give them.
        $this->assertSame($expected['post'], $without->post('save', componentClass: $class, componentId: 'Counter-abc123', props: ['id' => 7, 'tag' => 'a&b'], swap: SwapStrategy::InnerHTML, extra: ['hx-confirm' => 'Sure? "yes"']));
    }

    /**
     * Every short call carries its token on its own and the endpoint URL without it (@since 0.88.0);
     * the URL still has the token, for drivers written before 0.88. A preview's parameter is on both.
     */
    public function test_short_calls_carry_the_token_and_the_endpoint_url_apart(): void
    {
        $driver = new RecordingCallsDriver();
        $hd = new HtmxExtensionProvider($driver, $this->tokens, renderer: $this->renderer, debug: true, pathPrefix: fn (): string => '/de');
        CallbackEngine::$templates['ShortBox'] = fn (): string => $hd->post('add', ['row' => 1]) . $hd->get('remove') . $hd->on('saved');
        $context = \Preflow\Testing\ContextFactory::forTest()->with(new \Preflow\Core\Context\Preview\PreviewGrant('v1.preview.x.y'));
        \Preflow\Core\Context\Contexts::run($context, fn (): string => $this->renderer->render($this->component(ShortBox::class, ['key' => 'a', 'text' => 'Hello'])));

        $this->assertSame([], $this->errors);
        $this->assertCount(3, $driver->calls);
        $expected = [['post', '/de/--component/action', 'add'], ['get', '/de/--component/action', 'remove'], ['on', '/de/--component/render', 'render']];
        foreach ($expected as $n => [$i, $path, $action]) {
            $call = $driver->calls[$n] ?? null;
            $this->assertInstanceOf(\Preflow\Core\Http\Hypermedia\ActionCall::class, $call, $i);
            $this->assertNotNull($call->token, $i);
            $this->assertSame($path . '?_preview=v1.preview.x.y', $call->endpointUrl, "{$i}: the endpoint URL without the token, the preview's parameter kept");
            $this->assertSame($path . '?token=' . urlencode($call->token) . '&_preview=v1.preview.x.y', $call->url, "{$i}: the URL still has the token");
            $this->assertSame($action, $this->tokens->decode($call->token)->action, $i);
        }
    }

    public function test_short_call_outside_a_component_fails(): void
    {
        foreach ([fn () => $this->hd->post('add'), fn () => $this->hd->get('add', ['a' => 1]), fn () => $this->hd->on('saved'), fn () => $this->hd->region('rows'), fn () => $this->hd->hasParent()] as $i => $call) {
            try {
                $call();
                $this->fail("call {$i} outside a component's render worked");
            } catch (\LogicException $e) {
                $this->assertStringContainsString([2 => "'saved'", 3 => "'rows'", 4 => 'hd.hasParent()'][$i] ?? "'add'", $e->getMessage());
                $this->assertStringContainsString("component's template", $e->getMessage());
            }
        }
    }

    public function test_short_call_with_a_driver_without_state_support_fails(): void
    {
        foreach ([new OldCallsDriver(), new LazyHypermediaDriver(static fn (): HypermediaDriver => new OldCallsDriver(), OldCallsDriver::class)] as $driver) {
            $hd = new HtmxExtensionProvider($driver, $this->tokens, renderer: $this->renderer, debug: true);
            foreach (['post' => static fn (): string => (string) $hd->post('add'), 'on' => static fn (): string => (string) $hd->on('saved')] as $what => $call) {
                $this->errors = [];
                CallbackEngine::$templates['ShortBox'] = $call;
                $this->renderer->render($this->component(ShortBox::class, []));

                $this->assertCount(1, $this->errors, $what);
                $this->assertInstanceOf(\LogicException::class, $this->errors[0]);
                $this->assertStringContainsString(OldCallsDriver::class, $this->errors[0]->getMessage(), $what);
                $this->assertStringContainsString('DrivesComponentActions', $this->errors[0]->getMessage(), $what);
                $this->assertStringContainsString($what === 'on' ? "hd.on('saved')" : "hd.post('add')", $this->errors[0]->getMessage());
            }
        }
    }

    public function test_short_on_refreshes_the_component_with_its_state(): void
    {
        $seen = null;
        CallbackEngine::$templates['ShortCounter'] = function () use (&$seen): string {
            $seen = $this->hd->on('cartUpdated', ['target' => 'results']);

            return '<div ' . $seen . '></div>';
        };
        $counter = $this->component(ShortCounter::class, ['key' => 'c', 'start' => 2]);
        $html = $this->renderer->render($counter);

        $this->assertSame([], $this->errors);
        $this->assertInstanceOf(SafeHtml::class, $seen);
        $attributes = self::attributes((string) $seen);
        $this->assertSame('cartUpdated from:body', $attributes['hx-trigger']);
        $this->assertArrayHasKey('hx-post', $attributes, 'the state goes in the body');
        $this->assertStringContainsString('/--component/render?token=', $attributes['hx-post']);
        $payload = $this->payload($attributes['hx-post']);
        $this->assertSame('render', $payload->action);
        $this->assertTrue($payload->neutralCall);
        $this->assertTrue($payload->stateCarried);
        $id = $counter->getComponentId();
        $this->assertStringContainsString('#preflow-state--' . $id, $attributes['hx-include'], 'the carrier goes with the refresh');
        $this->assertStringContainsString('id="preflow-state--' . $id . '"', $html, 'and the page has it');
        $this->assertSame('#' . $id . '-results', $attributes['hx-target']);
        $this->assertSame('results', json_decode($attributes['hx-vals'], true)['_region']);
        $this->assertStringContainsString('hx-trigger', $attributes['hx-disinherit']);
    }

    public function test_short_on_takes_no_events_of_its_own(): void
    {
        CallbackEngine::$templates['ShortBox'] = fn (): string => (string) $this->hd->on('saved', ['on' => 'click']);
        $this->renderer->render($this->component(ShortBox::class, []));
        CallbackEngine::$templates['ShortBox'] = fn (): string => (string) $this->hd->on('saved now');
        $this->renderer->render($this->component(ShortBox::class, []));

        $this->assertCount(2, $this->errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->errors[0]);
        $this->assertStringContainsString('on', $this->errors[0]->getMessage());
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->errors[1]);
        $this->assertStringContainsString("'saved now'", $this->errors[1]->getMessage());
    }

    public function test_unknown_option_fails_in_debug(): void
    {
        CallbackEngine::$templates['ShortBox'] = fn (): string => (string) $this->hd->post('add', [], ['targte' => 'rows']);
        $this->renderer->render($this->component(ShortBox::class, []));

        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->errors[0]);
        $this->assertStringContainsString('targte', $this->errors[0]->getMessage());
    }

    public function test_region_prints_the_id_of_a_region(): void
    {
        $seen = null;
        CallbackEngine::$templates['ShortBox'] = function () use (&$seen): string {
            $seen = $this->hd->region('results');

            return '';
        };
        $box = $this->component(ShortBox::class, ['key' => 'a"b']);
        $this->renderer->render($box);

        $this->assertInstanceOf(SafeHtml::class, $seen);
        $this->assertSame('id="' . htmlspecialchars($box->getComponentId() . '-results', ENT_QUOTES) . '"', (string) $seen);

        $this->assertSame([], $this->errors);

        CallbackEngine::$templates['ShortBox'] = fn (): string => (string) $this->hd->region('no region');
        $this->renderer->render($this->component(ShortBox::class, []));
        $this->assertCount(1, $this->errors);
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->errors[0]);
        $this->assertStringContainsString("'no region'", $this->errors[0]->getMessage());
    }

    /**
     * The attributes in $html, unescaped.
     *
     * @return array<string, string>
     */
    private static function attributes(string $html): array
    {
        preg_match_all('/([a-z-]+)="([^"]*)"/', $html, $matches, PREG_SET_ORDER);
        $attributes = [];
        foreach ($matches as [, $name, $value]) {
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    private function payload(string $url): \Preflow\Htmx\TokenPayload
    {
        return $this->tokens->decode($this->token($url));
    }

    private function token(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) $query['token'];
    }

    /** A new request: a new renderer and hd, as the endpoint has them. */
    private function freshRequest(): void
    {
        $this->errors = [];
        $driver = new HtmxDriver(new ResponseHeaders(), true);
        $this->renderer = $this->renderer($driver);
        $this->hd = new HtmxExtensionProvider($driver, $this->tokens, renderer: $this->renderer, debug: true);
    }

    /** @param (\Closure(string, array<string, mixed>): Component)|null $factory */
    private function endpoint(?\Closure $factory = null): \Preflow\Htmx\ComponentEndpoint
    {
        return new \Preflow\Htmx\ComponentEndpoint(
            token: $this->tokens,
            renderer: $this->renderer,
            driver: new HtmxDriver(new ResponseHeaders(), true),
            componentFactory: $factory ?? function (string $class, array $props): Component {
                return $this->component($class, $props);
            },
            debug: true,
        );
    }

    /**
     * @param class-string<Component> $class
     * @param array<string, mixed> $props
     */
    private function component(string $class, array $props): Component
    {
        $component = new $class();
        $component->setProps($props);

        return $component;
    }

    private function renderer(HtmxDriver $driver): ComponentRenderer
    {
        $renderer = new ComponentRenderer(new CallbackEngine(), new ErrorBoundary(debug: DebugLevel::On, onError: function (\Throwable $e): void {
            $this->errors[] = $e;
        }));
        $signed = new SignedStateStore(self::KEY);
        $renderer->enableState(static fn (string $store): StateStore => $signed, true);
        $renderer->carryStateWith($driver->stateCarrier(...));

        return $renderer;
    }
}
