<?php

declare(strict_types=1);

namespace Preflow\Htmx\Tests;

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Htmx\HtmxDriver;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\ResponseHeaders;
use Preflow\Htmx\SwapStrategy;
use Preflow\Core\Http\Hypermedia\ActionCall;
use Preflow\Core\Http\Hypermedia\ActionOptions;

final class HtmxDriverTest extends TestCase
{
    private ResponseHeaders $headers;
    private HtmxDriver $driver;

    protected function setUp(): void
    {
        $this->headers = new ResponseHeaders();
        $this->driver = new HtmxDriver($this->headers);
    }

    private function createRequest(array $headers = []): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function test_action_attrs_generates_hx_post(): void
    {
        $attrs = $this->driver->actionAttrs('post', '/action', 'comp-123', SwapStrategy::OuterHTML);

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-post="/action"', $result);
        $this->assertStringContainsString('hx-target="#comp-123"', $result);
        $this->assertStringContainsString('hx-swap="outerHTML"', $result);
    }

    public function test_action_attrs_generates_hx_get(): void
    {
        $attrs = $this->driver->actionAttrs('get', '/data', 'target-1', SwapStrategy::InnerHTML);

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-get="/data"', $result);
        $this->assertStringContainsString('hx-swap="innerHTML"', $result);
    }

    public function test_action_attrs_with_extra(): void
    {
        $attrs = $this->driver->actionAttrs('post', '/act', 'c-1', SwapStrategy::OuterHTML, [
            'hx-confirm' => 'Are you sure?',
        ]);

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-confirm="Are you sure?"', $result);
    }

    public function test_listen_attrs_generates_trigger(): void
    {
        $attrs = $this->driver->listenAttrs('cartUpdated', '/refresh', 'cart-1');

        $result = (string) $attrs;
        $this->assertStringContainsString('hx-trigger="cartUpdated from:body"', $result);
        $this->assertStringContainsString('hx-get="/refresh"', $result);
        $this->assertStringContainsString('hx-target="#cart-1"', $result);
    }

    public function test_trigger_event_sets_header(): void
    {
        $this->driver->triggerEvent('itemAdded');

        $this->assertSame('itemAdded', $this->headers->get('HX-Trigger'));
    }

    public function test_redirect_sets_header(): void
    {
        $this->driver->redirect('/dashboard');

        $this->assertSame('/dashboard', $this->headers->get('HX-Redirect'));
    }

    public function test_push_url_sets_header(): void
    {
        $this->driver->pushUrl('/new-page');

        $this->assertSame('/new-page', $this->headers->get('HX-Push-Url'));
    }

    public function test_is_fragment_request_true(): void
    {
        $request = $this->createRequest(['HX-Request' => 'true']);

        $this->assertTrue($this->driver->isFragmentRequest($request));
    }

    public function test_is_fragment_request_false(): void
    {
        $request = $this->createRequest();

        $this->assertFalse($this->driver->isFragmentRequest($request));
    }

    public function test_asset_tag_is_empty_because_htmx_is_self_hosted(): void
    {
        // htmx is inlined by HtmxAssets; the driver no longer emits an external <script src>.
        $this->assertSame('', $this->driver->assetTag());
    }

    public function test_fragment_target_is_hx_target(): void
    {
        $this->assertSame('cart', $this->driver->fragmentTarget($this->createRequest(['HX-Target' => 'cart'])));
        $this->assertNull($this->driver->fragmentTarget($this->createRequest(['HX-Request' => 'true'])));
    }

    public function test_apply_effects_sends_the_recorded_headers(): void
    {
        $this->driver->triggerEvent('saved');
        $this->driver->pushUrl('/items/2');

        $response = $this->driver->applyEffects(new \Nyholm\Psr7\Response(200));

        $this->assertSame('saved', $response->getHeaderLine('HX-Trigger'));
        $this->assertSame('/items/2', $response->getHeaderLine('HX-Push-Url'));
        $this->assertFalse($response->hasHeader('HX-Nonce'), 'no nonce without the htmx assets');
    }

    public function test_apply_effects_announces_the_page_nonce(): void
    {
        $collector = new \Preflow\View\AssetCollector(new \Preflow\View\NonceGenerator());
        $this->driver->useAssets(new \Preflow\Htmx\HtmxAssets($collector, 'never'));

        $response = $this->driver->applyEffects(new \Nyholm\Psr7\Response(200));

        $this->assertSame($collector->getNonce(), $response->getHeaderLine('HX-Nonce'));
    }

    /** A preview token handed on in the page: hx-headers on <body>, every htmx request from the page sends it. */
    public function test_carry_headers_puts_hx_headers_on_the_body(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $headers = ['X-Preflow-Preview' => 'v1.a"b\'c<d>&e'];
        $decode = static fn (string $html): array => preg_match('#<body[^>]* hx-headers="([^"]*)"#', $html, $m) === 1 ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : [];

        $plain = $driver->carryHeaders('<html><body class="page"><p>x</p></body></html>', $headers);
        $this->assertSame($headers, $decode($plain), 'escaped for the attribute, read back exactly');
        $this->assertStringContainsString('<body class="page" hx-headers="', $plain);
        $this->assertStringEndsWith('><p>x</p></body></html>', $plain, 'nothing else changes');

        $own = $driver->carryHeaders("<body hx-headers='{\"X-Own\":\"1\"}'>", $headers);
        $this->assertSame($headers + ['X-Own' => '1'], $decode($own), 'the page\'s own headers stay');

        $this->assertSame('<p>fragment</p>', $driver->carryHeaders('<p>fragment</p>', $headers), 'no body, no change');
        $this->assertSame('<body hx-headers="js:x()" hx-history="false">', $driver->carryHeaders('<body hx-headers="js:x()">', $headers), 'a script value is left alone (out of history still)');
        $this->assertSame('<body hx-headers={} hx-history="false">', $driver->carryHeaders('<body hx-headers={}>', $headers), 'an unquoted one too: never a second hx-headers');

        // The real <body>: not one in a comment or a script string; a quoted ">" in its attributes is fine.
        $page = '<head><!-- <body class="old"> --><script>var t = "<body>";</script></head><body data-x="a>b"><p>x</p></body>';
        $carried = $driver->carryHeaders($page, $headers);
        $this->assertStringContainsString('<!-- <body class="old"> --><script>var t = "<body>";</script></head><body data-x="a>b" hx-headers="', $carried);
        $this->assertSame(1, preg_match('# hx-headers="([^"]*)"#', $carried, $m));
        $this->assertSame($headers, json_decode(html_entity_decode($m[1], ENT_QUOTES), true));
    }

    /** Task 22 I1: a page that carries the token is kept out of htmx's history cache; the page's own hx-history is replaced. */
    public function test_carry_headers_keeps_the_page_out_of_history(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());

        $carried = $driver->carryHeaders('<body hx-history="true" class="p"><p>x</p></body>', ['X-Preflow-Preview' => 't']);

        $this->assertStringContainsString(' hx-history="false"', $carried);
        $this->assertStringNotContainsString('hx-history="true"', $carried);
        $this->assertSame(1, substr_count($carried, 'hx-history'));
        $this->assertStringContainsString('class="p"', $carried);
    }

    /**
     * Task 22 re-check: the page stays out of htmx's history also where the headers can't be added:
     * a body whose own hx-headers is a js: value or unquoted (no token there), and a whole document
     * without a <body> tag (one is added, with the headers). A fragment is left alone.
     */
    public function test_carry_headers_keeps_history_off_where_it_cannot_carry_the_headers(): void
    {
        $driver = new HtmxDriver(new ResponseHeaders());
        $headers = ['X-Preflow-Preview' => 'the-token'];

        foreach (['<body hx-headers="js:{a: 1}" hx-history="true">', '<body hx-headers={a}>'] as $body) {
            $carried = $driver->carryHeaders($body . '<p>x</p></body>', $headers);
            $this->assertStringContainsString(' hx-history="false">', $carried, $body);
            $this->assertStringNotContainsString('the-token', $carried, 'the token is left out: ' . $body);
            $this->assertSame(1, substr_count($carried, 'hx-history'));
        }

        $document = $driver->carryHeaders('<!DOCTYPE html><html><head><title>T</title></head><p>x</p></html>', $headers);
        $this->assertStringContainsString('</head><body hx-headers="', $document);
        $this->assertStringContainsString(' hx-history="false"><p>x</p>', $document);
        $this->assertStringContainsString('the-token', $document);
        $this->assertSame('<p>fragment</p>', $driver->carryHeaders('<p>fragment</p>', $headers));
    }

    /** @param array<string, mixed> $options */
    private function call(array $options, array $values = [], string $targetId = 'Box-1', ?HtmxDriver $driver = null, string $componentId = 'Box-1'): array
    {
        return ($driver ?? $this->driver)->callAttributes(new ActionCall('post', '/--component/action?token=t', $componentId, $targetId, ActionOptions::from($options, true), $values));
    }

    public function test_neutral_options_translate_to_htmx(): void
    {
        $default = $this->call([]);
        $this->assertSame([
            'hx-post' => '/--component/action?token=t',
            'hx-target' => '#Box-1',
            'hx-swap' => 'outerHTML',
            'hx-include' => '#preflow-state--Box-1',
            'hx-sync' => '#Box-1:queue all',
            'hx-vals' => '{"_values":"{}","_region":""}',
            'hx-disinherit' => 'hx-target hx-swap hx-include hx-sync',
        ], $default, 'one request per component at a time: queued; values and region always explicit');

        foreach (['outer' => 'outerHTML', 'delete' => 'delete', 'none' => 'none'] as $neutral => $htmx) {
            $this->assertSame($htmx, $this->call(['swap' => $neutral])['hx-swap']);
        }
        foreach (['inner' => 'innerHTML', 'prepend' => 'afterbegin', 'append' => 'beforeend', 'none' => 'none'] as $neutral => $htmx) {
            $this->assertSame($htmx, $this->call(['target' => 'results', 'swap' => $neutral], targetId: 'Box-1-results')['hx-swap']);
        }

        $region = $this->call(['target' => 'results'], targetId: 'Box-1-results');
        $this->assertSame('#Box-1-results', $region['hx-target']);
        $this->assertSame('innerHTML', $region['hx-swap']);
        $this->assertSame(['_values' => '{}', '_region' => 'results'], json_decode($region['hx-vals'], true));

        $this->assertSame('click, change', $this->call(['on' => ['click', 'change']])['hx-trigger']);
        $this->assertSame('revealed', $this->call(['on' => 'visible'])['hx-trigger']);
        $this->assertSame('blur', $this->call(['on' => 'blur'])['hx-trigger']);
        $this->assertSame('load', $this->call(['on' => 'load'])['hx-trigger']);
        $this->assertSame('submit', $this->call(['on' => 'submit'])['hx-trigger']);
        $this->assertSame('input delay:300ms, change', $this->call(['on' => ['input', 'change'], 'debounce' => 300])['hx-trigger'], 'debounce delays typing only');

        $this->assertSame('#Box-1:replace', $this->call(['latest' => true])['hx-sync'], 'a newer request replaces whatever runs on the component');
        $this->assertSame('#preflow-state--Box-1, #Box-1-filters, #Box-1 [name="filters"], #Box-1-q, #Box-1 [name="q"]', $this->call(['include' => ['filters', 'q']])['hx-include']);
        $this->assertSame('this', $this->call(['disable' => 'self'])['hx-disabled-elt']);
        $this->assertSame('#Box-1-results', $this->call(['disable' => 'results'])['hx-disabled-elt']);
        $this->assertSame('innerHTML show:#Box-1-results:top', $this->call(['target' => 'results', 'scroll' => 'top'], targetId: 'Box-1-results')['hx-swap']);
        $this->assertArrayNotHasKey('hx-trigger', $default, "no on: htmx's default event for the element");
    }

    public function test_values_travel_as_one_json_object(): void
    {
        $attributes = $this->call([], ['page' => 2, 'q' => 'a"b', 'rows' => [['name' => 'x']], 'all' => true, 'none' => null]);

        $values = json_decode($attributes['hx-vals'], true)['_values'];
        $this->assertIsString($values, 'one field: an ancestor\'s values never mix in');
        $this->assertSame(['page' => 2, 'q' => 'a"b', 'rows' => [['name' => 'x']], 'all' => '1', 'none' => ''], json_decode($values, true));

        $this->expectException(\InvalidArgumentException::class);
        $this->call([], ['when' => new \DateTimeImmutable()]);
    }

    public function test_a_call_inside_another_calls_element_sends_only_its_own_values_and_region(): void
    {
        // A form that searches into the results region, and a button inside it that saves the component.
        $form = $this->call(['target' => 'results', 'disable' => 'self'], ['q' => 'bern'], targetId: 'Box-1-results');
        $button = $this->call([], ['page' => 2]);

        $this->assertSame('hx-target hx-swap hx-include hx-sync hx-disabled-elt', $form['hx-disinherit'], 'what the form call set never reaches the button; everything else inherits');
        $withHatch = $this->call(['htmx' => ['hx-confirm' => 'Sure?', 'data-hx-indicator' => '#spin']]);
        $this->assertSame('hx-target hx-swap hx-include hx-sync hx-confirm hx-indicator', $withHatch['hx-disinherit'], "the escape hatch's names too");
        // htmx merges the hx-vals of every ancestor, the element's own keys first: the button defines both keys.
        $merged = json_decode($button['hx-vals'], true) + json_decode($form['hx-vals'], true);
        $this->assertSame('', $merged['_region'], 'the whole component, not the form\'s region');
        $this->assertSame(['page' => 2], json_decode($merged['_values'], true), 'only its own values');
        $this->assertSame(['_values', '_region'], array_keys($merged));
    }

    public function test_escape_hatch_passes_htmx_attributes_through(): void
    {
        $attributes = $this->call(['htmx' => ['hx-confirm' => 'Sure?', 'hx-swap' => 'morph', 'hx-push-url' => true]]);

        $this->assertSame('Sure?', $attributes['hx-confirm']);
        $this->assertSame('morph', $attributes['hx-swap'], 'the escape hatch wins over the neutral translation');
        $this->assertSame('true', $attributes['hx-push-url']);
    }

    public function test_escape_hatch_cannot_replace_what_the_call_needs(): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        foreach (['hx-include', 'hx-vals', 'hx-sync', 'hx-target', 'data-hx-vals'] as $name) {
            try {
                $this->call(['htmx' => [$name => 'x']], driver: new HtmxDriver(new ResponseHeaders(), true, $log));
                $this->fail("{$name} passed in debug");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }

        $production = $this->call(['htmx' => ['hx-vals' => '{"x":1}', 'hx-confirm' => 'Sure?']], driver: new HtmxDriver(new ResponseHeaders(), false, $log));
        $this->assertSame('{"_values":"{}","_region":""}', $production['hx-vals'], 'left out in production');
        $this->assertSame('Sure?', $production['hx-confirm']);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('hx-vals', $lines[0]);
    }

    public function test_another_drivers_key_is_ignored_with_one_warning_in_debug(): void
    {
        $lines = [];
        $debug = new HtmxDriver(new ResponseHeaders(), true, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $attributes = $this->call(['datastar' => ['data-x' => '1']], driver: $debug);
        $this->call(['datastar' => ['data-x' => '2']], driver: $debug);
        \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest(), fn () => $this->call(['datastar' => ['data-x' => '3']], driver: $debug));

        $this->assertArrayNotHasKey('data-x', $attributes);
        $this->assertCount(2, $lines, 'once per key and request (each render frame its own)');
        $this->assertStringContainsString('datastar', $lines[0]);

        $quiet = [];
        $this->call(['datastar' => ['data-x' => '1']], driver: new HtmxDriver(new ResponseHeaders(), false, static function (string $line) use (&$quiet): void {
            $quiet[] = $line;
        }));
        $this->assertSame([], $quiet, 'no warning outside debug');
    }

    public function test_component_ids_are_escaped_in_selectors(): void
    {
        $attributes = $this->call(['include' => 'q', 'scroll' => 'top'], targetId: 'Box-1-a.b:c', componentId: 'Box-1-a.b:c');

        $this->assertSame('#Box-1-a\\00002eb\\00003ac', $attributes['hx-target']);
        $this->assertSame('#Box-1-a\\00002eb\\00003ac:queue all', $attributes['hx-sync'], 'no colon or space in the selector htmx splits');
        $this->assertSame('#preflow-state--Box-1-a\\00002eb\\00003ac, #Box-1-a\\00002eb\\00003ac-q, #Box-1-a\\00002eb\\00003ac [name="q"]', $attributes['hx-include']);
        $this->assertSame('outerHTML show:#Box-1-a\\00002eb\\00003ac:top', $attributes['hx-swap']);
    }

    public function test_state_carrier_is_outside_every_form_and_the_update_swaps_it_out_of_band(): void
    {
        $carrier = $this->driver->stateCarrier('Box-1', 'a"b<c');
        $this->assertSame('<input type="hidden" id="preflow-state--Box-1" name="_state[Box-1]" form="preflow-no-form" value="a&quot;b&lt;c">', $carrier);
        $this->assertSame('<input type="hidden" id="preflow-state--Box-1" name="_state[Box-1]" form="preflow-no-form" value="a&quot;b&lt;c" hx-swap-oob="true">', $this->driver->stateUpdate('Box-1', 'a"b<c'));
    }
}
