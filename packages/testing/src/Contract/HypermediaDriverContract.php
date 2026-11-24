<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\Hypermedia\ActionCall;
use Preflow\Core\Http\Hypermedia\ActionOptions;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Preflow\Core\Http\Hypermedia\OwnsResponseHeaders;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\SwapStrategy;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Testing\ContextFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What every HypermediaDriver does, as tests. Extend it, implement the three hooks, and run it
 * with PHPUnit:
 *
 *     final class MyDriverContractTest extends HypermediaDriverContract
 *     {
 *         protected function createDriver(): HypermediaDriver
 *         {
 *             return new MyDriver();
 *         }
 *
 *         protected function fragmentRequest(ServerRequestInterface $request): ServerRequestInterface
 *         {
 *             return $request->withHeader('My-Request', 'true');
 *         }
 *
 *         protected function targetRequest(ServerRequestInterface $request, string $targetId): ServerRequestInterface
 *         {
 *             return $request->withHeader('My-Target', $targetId);
 *         }
 *     }
 *
 * - createDriver() is called once per test (in setUp()).
 * - fragmentRequest() marks a request the way the driver's client marks the requests it sends
 *   (a header, a query parameter…).
 * - targetRequest() marks a request the way the client says which element the response goes
 *   into (for fragmentTarget()).
 * - effects() (optional) lists what the driver recorded for the response (triggerEvent(),
 *   redirect(), pushUrl()) since createDriver(), as strings. By default it is the response
 *   applyEffects() makes of an empty one: each header as "Name: value", then the body.
 * - historyRestoreRequest() (optional) marks a request the way the client asks for a whole
 *   page again (htmx: HX-History-Restore-Request); null (default) when the client has none.
 * - pageNonceRequest() (optional) marks a request the way the client sends back its page's CSP
 *   nonce; null (default) when it doesn't.
 *
 * The suite asserts no attribute or header names: a driver chooses its own. It checks that:
 *
 * - the URL a caller passes appears verbatim in an attribute value: as the whole value (htmx:
 *   `hx-get="/items"`), or, where the value is a JS expression (Datastar: `@get(…)`), as a JSON
 *   string literal of it, json_encode($url, JSON_UNESCAPED_SLASHES) (JSON_UNESCAPED_UNICODE and
 *   the JSON_HEX_* flags are allowed too). A URL pasted into a quoted JS string without encoding
 *   fails: the test URL holds a `'`, a `"` and `<`;
 * - the target id is in an attribute value; the method and the event are in an attribute name
 *   or value (htmx writes `hx-post`, Datastar `data-on-…`);
 * - the attributes render without a raw `<` or a quote that ends the attribute;
 * - fragmentTarget() is the id targetRequest() sent, and null for a plain request;
 * - triggerEvent(), redirect() and pushUrl() show their event or URL in effects(), and in the
 *   response applyEffects() returns (a header or the body), which keeps its status, its headers
 *   and its content;
 * - the HypermediaProtocol side core uses: a plain request is no history restore and carries no
 *   page nonce; a history restore (if the client has one) is also a fragment request;
 *   varyHeaders() are header names; navigate() puts the URL in a header or the body and changes
 *   the response for a reload (null); announceNonce() keeps the content and writes only headers
 *   nonceHeaders() lists (the page cache never stores those); pageNonce() returns the nonce
 *   pageNonceRequest() sent; core (HypermediaProtocols::pageNonce()) takes it from a fragment request only, never
 *   from a plain request or a history restore;
 * - since 0.81, effects stay in the render frame they were recorded in: one recorded in a page's
 *   frame is not in the response of a render in a frame of its own (Contexts::run()) inside it,
 *   and one recorded there is not in the page's; one recorded inside Contexts::within() is the
 *   frame's; outside every render the effects are the driver's own;
 * - since 0.81, for a driver that implements OwnsResponseHeaders (optional; skipped otherwise),
 *   ownedHeaders() names the response headers the driver writes: every header
 *   applyEffects(), navigate() and announceNonce() add is in ownedHeaders() or nonceHeaders()
 *   (a name ending in '*' is a prefix), so a page cannot set them as response hints;
 * - since 0.86, for a driver that implements DrivesComponentActions (optional; skipped otherwise):
 *   callAttributes() names the URL and the target, and every neutral option (ActionOptions) and
 *   the values change its attributes (`component` excepted: the caller resolves it into the
 *   component and target ids); stateCarrier() and stateUpdate() name the component and hold
 *   the state string.
 */
abstract class HypermediaDriverContract extends TestCase
{
    use ChecksFrameBalance;

    /** The driver under test. */
    abstract protected function createDriver(): HypermediaDriver;

    /** $request, marked as one the driver's client sends (so isFragmentRequest() is true). */
    abstract protected function fragmentRequest(ServerRequestInterface $request): ServerRequestInterface;

    /** $request, marked as one whose response the client swaps into the element with this id. */
    abstract protected function targetRequest(ServerRequestInterface $request, string $targetId): ServerRequestInterface;

    /** $request marked as a history restore (a whole page again), or null when the client has none. */
    protected function historyRestoreRequest(ServerRequestInterface $request): ?ServerRequestInterface
    {
        return null;
    }

    /** $request carrying the page's CSP nonce back, or null when the client doesn't send it. */
    protected function pageNonceRequest(ServerRequestInterface $request, string $nonce): ?ServerRequestInterface
    {
        return null;
    }

    /**
     * The response-side effects the driver recorded since createDriver(). By default: the
     * response applyEffects() makes of an empty one, each header as "Name: value", then the body.
     *
     * @return list<string>
     */
    protected function effects(): array
    {
        return self::lines($this->driver->applyEffects(new Response(200)));
    }

    private HypermediaDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->createDriver();
    }

    public function test_action_attributes_name_the_url_the_method_and_the_target(): void
    {
        foreach (['post', 'delete'] as $method) {
            $attrs = $this->driver->actionAttrs($method, '/items/7/save', 'item-7', SwapStrategy::OuterHTML);

            $this->assertUrlInValues('/items/7/save', $attrs);
            $this->assertStringContainsString('item-7', implode("\n", $attrs->toArray()), 'the target id is in an attribute value');
            $this->assertStringContainsStringIgnoringCase($method, (string) $attrs, "the method {$method} is in an attribute name or value");
        }
    }

    public function test_action_attributes_keep_the_extra_attributes(): void
    {
        $attrs = $this->driver->actionAttrs('get', '/items', 'list', SwapStrategy::InnerHTML, ['data-extra' => 'kept']);

        $this->assertSame('kept', $attrs->toArray()['data-extra'] ?? null, 'an extra attribute is passed through as given');
    }

    public function test_listen_attributes_name_the_event_the_url_and_the_target(): void
    {
        $attrs = $this->driver->listenAttrs('cart-updated', '/cart/summary', 'cart-box');
        $values = implode("\n", $attrs->toArray());

        $this->assertStringContainsString('cart-updated', (string) $attrs, 'the event is in an attribute name or value');
        $this->assertUrlInValues('/cart/summary', $attrs);
        $this->assertStringContainsString('cart-box', $values, 'the target id is in an attribute value');
    }

    public function test_attributes_render_escaped(): void
    {
        $url = '/search?q="<x>"&it\'s=2';
        $attrs = $this->driver->actionAttrs('get', $url, 'results', SwapStrategy::InnerHTML);

        $this->assertInstanceOf(HtmlAttributes::class, $attrs);
        $this->assertUrlInValues($url, $attrs);
        $html = (string) $attrs;
        $this->assertStringNotContainsString('<x>', $html, 'a value is HTML-escaped when the attributes render');
        $this->assertStringNotContainsString('"<', $html, 'a quote in a value does not end the attribute');
    }

    public function test_a_plain_request_is_not_a_fragment_request(): void
    {
        $this->assertFalse($this->driver->isFragmentRequest(new ServerRequest('GET', '/items')));
    }

    public function test_a_request_from_the_drivers_client_is_a_fragment_request(): void
    {
        $request = $this->fragmentRequest(new ServerRequest('POST', '/items/7/save'));

        $this->assertTrue($this->driver->isFragmentRequest($request), 'a request marked by fragmentRequest() is a fragment request');
    }

    public function test_a_plain_request_names_no_fragment_target(): void
    {
        $this->assertNull($this->driver->fragmentTarget(new ServerRequest('GET', '/items')));
    }

    public function test_the_fragment_target_is_the_id_the_client_sent(): void
    {
        $request = $this->targetRequest($this->fragmentRequest(new ServerRequest('POST', '/items/7/save')), 'cart-box');

        $this->assertSame('cart-box', $this->driver->fragmentTarget($request), 'fragmentTarget() is the id targetRequest() sent, without a "#"');
    }

    public function test_the_effects_reach_the_response(): void
    {
        $this->driver->triggerEvent('cart-updated');
        $this->driver->redirect('/thanks');
        $this->driver->pushUrl('/items?page=2');

        $response = $this->driver->applyEffects(new Response(200, ['X-Kept' => 'yes'], '<p>the fragment</p>'));
        $lines = implode("\n", self::lines($response));

        foreach (['cart-updated', '/thanks', '/items?page=2'] as $expected) {
            $this->assertStringContainsString($expected, $lines, sprintf('applyEffects() puts "%s" in a header or the body; the response has: %s', $expected, $lines));
        }
        $this->assertSame(200, $response->getStatusCode(), 'applyEffects() keeps the status');
        $this->assertSame('yes', $response->getHeaderLine('X-Kept'), 'applyEffects() keeps the headers');
        $this->assertStringContainsString('<p>the fragment</p>', (string) $response->getBody(), 'applyEffects() keeps the content');
    }

    public function test_without_effects_the_response_keeps_its_content(): void
    {
        $response = $this->driver->applyEffects(new Response(200, ['X-Kept' => 'yes'], '<p>the fragment</p>'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Kept'));
        $this->assertStringContainsString('<p>the fragment</p>', (string) $response->getBody());
    }

    public function test_a_plain_request_is_no_history_restore_and_sends_no_page_nonce(): void
    {
        $plain = new ServerRequest('GET', '/items');

        $this->assertFalse($this->driver->isHistoryRestore($plain));
        $this->assertFalse($this->driver->isHistoryRestore($this->fragmentRequest($plain)), 'a fragment request is no history restore');
        $this->assertNull($this->driver->pageNonce($plain));
    }

    public function test_a_history_restore_is_a_fragment_request_too(): void
    {
        $request = $this->historyRestoreRequest(new ServerRequest('GET', '/items'));
        if ($request === null) {
            $this->markTestSkipped('The client has no history restore (historyRestoreRequest() is null).');
        }

        $this->assertTrue($this->driver->isHistoryRestore($request));
        $this->assertTrue($this->driver->isFragmentRequest($request), 'core finds the client of a history restore through isFragmentRequest()');
    }

    public function test_vary_headers_are_header_names(): void
    {
        foreach ($this->driver->varyHeaders() as $name) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]+$/', $name, 'varyHeaders() lists request header names');
        }
        $this->addToAssertionCount(1);
    }

    public function test_navigate_tells_the_url_or_a_reload(): void
    {
        $plain = new Response(401, ['X-Kept' => 'yes']);
        $to = $this->driver->navigate($plain, '/login?next=%2Fjobs');
        $lines = implode("\n", self::lines($to));

        $this->assertStringContainsString('/login?next=%2Fjobs', $lines, 'navigate() puts the URL in a header or the body; the response has: ' . $lines);
        $this->assertSame('yes', $to->getHeaderLine('X-Kept'), 'navigate() keeps the headers');
        $this->assertNotSame(self::lines($plain), self::lines($this->driver->navigate($plain, null)), 'navigate(null) asks for a reload');
    }

    public function test_announce_nonce_writes_only_its_nonce_headers(): void
    {
        $response = new Response(200, ['X-Kept' => 'yes'], '<p>the fragment</p>');
        $announced = $this->driver->announceNonce($response, 'cGFnZS1ub25jZS0xMjM0NQ==');

        $this->assertSame(200, $announced->getStatusCode());
        $this->assertSame('yes', $announced->getHeaderLine('X-Kept'));
        $this->assertSame('<p>the fragment</p>', (string) $announced->getBody(), 'announceNonce() keeps the content');
        $own = array_map(strtolower(...), $this->driver->nonceHeaders());
        foreach (array_keys($announced->getHeaders()) as $name) {
            if (!$response->hasHeader((string) $name)) {
                $this->assertContains(strtolower((string) $name), $own, "announceNonce() wrote {$name}: nonceHeaders() must list it");
            }
        }
    }

    public function test_page_nonce_is_the_one_the_client_sent(): void
    {
        $request = $this->pageNonceRequest($this->fragmentRequest(new ServerRequest('POST', '/items/7/save')), 'cGFnZS1ub25jZS0xMjM0NQ==');
        if ($request === null) {
            $this->markTestSkipped('The client sends no page nonce (pageNonceRequest() is null).');
        }

        $this->assertSame('cGFnZS1ub25jZS0xMjM0NQ==', $this->driver->pageNonce($request));
    }

    public function test_core_takes_a_page_nonce_only_from_a_fragment_request(): void
    {
        $nonce = 'cGFnZS1ub25jZS0xMjM0NQ==';
        $plain = $this->pageNonceRequest(new ServerRequest('GET', '/items'), $nonce);
        if ($plain === null) {
            $this->markTestSkipped('The client sends no page nonce (pageNonceRequest() is null).');
        }
        $clients = new HypermediaProtocols();
        $clients->add($this->driver);

        $this->assertNull($clients->pageNonce($plain), 'a plain request carrying a nonce adopts nothing');
        $restore = $this->historyRestoreRequest(new ServerRequest('GET', '/items'));
        if ($restore !== null) {
            $this->assertNull($clients->pageNonce($this->pageNonceRequest($restore, $nonce) ?? $restore), 'a history restore adopts nothing');
        }
        $fragment = $this->pageNonceRequest($this->fragmentRequest(new ServerRequest('POST', '/items/7/save')), $nonce);
        $this->assertSame($nonce, $clients->pageNonce($fragment ?? throw new \LogicException('pageNonceRequest() returned null')));
    }

    public function test_effects_stay_in_the_render_frame_they_were_recorded_in(): void
    {
        $page = ContextFactory::forTest();
        $other = $page->in('xx')->because(\Preflow\Core\Context\Purpose::Audit)->discarding()->skippingWrites();

        [$inner, $outer] = Contexts::run($page, function () use ($other): array {
            $this->driver->triggerEvent('page-event');
            $inner = Contexts::run($other, function (): string {
                $before = self::text($this->driver->applyEffects(new Response(200)));
                $this->driver->triggerEvent('render-event');

                return $before . "\n---\n" . self::text($this->driver->applyEffects(new Response(200)));
            });
            Contexts::within(Contexts::current()->in('xx'), function (): void {
                $this->driver->pushUrl('/within-the-frame');
            });

            return [$inner, self::text($this->driver->applyEffects(new Response(200)))];
        });
        [$innerBefore, $innerAfter] = explode("\n---\n", $inner);

        $this->assertStringNotContainsString('page-event', $innerBefore, 'an effect the page recorded is not in the response of a render in a frame of its own');
        $this->assertStringContainsString('render-event', $innerAfter, 'the render\'s own effect is in its response');
        $this->assertStringNotContainsString('page-event', $innerAfter);
        $this->assertStringContainsString('page-event', $outer, 'the page\'s effect is still in its response');
        $this->assertStringNotContainsString('render-event', $outer, 'the render\'s effect never reaches the page\'s response');
        $this->assertStringContainsString('/within-the-frame', $outer, 'Contexts::within() keeps the frame: its effect is the page\'s');
        $this->assertStringNotContainsString('page-event', self::text($this->driver->applyEffects(new Response(200))), 'outside every render the effects are the driver\'s own');
    }

    public function test_owned_headers_name_every_header_the_driver_writes(): void
    {
        if (!$this->driver instanceof OwnsResponseHeaders) {
            $this->markTestSkipped('implement OwnsResponseHeaders so pages cannot set your headers');
        }
        $owned = [...$this->driver->ownedHeaders(), ...$this->driver->nonceHeaders()];
        foreach ($this->driver->ownedHeaders() as $name) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]+\*?$/', $name, 'ownedHeaders() lists header names, a trailing * for a prefix');
        }
        $plain = new Response(200, ['X-Kept' => 'yes'], '<p>the fragment</p>');
        $this->driver->triggerEvent('cart-updated');
        $this->driver->redirect('/thanks');
        $this->driver->pushUrl('/items?page=2');
        $written = [
            'applyEffects()' => $this->driver->applyEffects($plain),
            'navigate($url)' => $this->driver->navigate($plain, '/login'),
            'navigate(null)' => $this->driver->navigate($plain, null),
            'announceNonce()' => $this->driver->announceNonce($plain, 'cGFnZS1ub25jZS0xMjM0NQ=='),
        ];
        foreach ($written as $call => $response) {
            foreach (array_keys($response->getHeaders()) as $name) {
                if (!$plain->hasHeader((string) $name)) {
                    $this->assertTrue(HypermediaProtocols::matches((string) $name, $owned), "{$call} wrote {$name}: ownedHeaders() (or nonceHeaders()) must name it");
                }
            }
        }
    }

    public function test_trigger_event_is_recorded(): void
    {
        $this->driver->triggerEvent('cart-updated');

        $this->assertEffect('cart-updated', 'triggerEvent()');
    }

    public function test_redirect_is_recorded(): void
    {
        $this->driver->redirect('/thanks');

        $this->assertEffect('/thanks', 'redirect()');
    }

    public function test_push_url_is_recorded(): void
    {
        $this->driver->pushUrl('/items?page=2');

        $this->assertEffect('/items?page=2', 'pushUrl()');
    }

    /**
     * The URL is one attribute's whole value, or sits in a value as a JSON string literal (for a
     * value that is a JS expression).
     */
    private function assertUrlInValues(string $url, HtmlAttributes $attrs): void
    {
        $literals = [];
        foreach (self::JSON_FLAG_SETS as $flags) {
            $literals[] = json_encode($url, $flags | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        foreach ($attrs->toArray() as $value) {
            if ($value === $url || array_any($literals, static fn (string $literal): bool => str_contains($value, $literal))) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail(sprintf(
            'The URL %s is an attribute value, or a JSON string literal in one (json_encode with JSON_UNESCAPED_SLASHES); the values are: %s',
            $url,
            implode(' | ', $attrs->toArray()),
        ));
    }

    /** The json_encode() flag sets accepted for a URL literal (each also with JSON_UNESCAPED_SLASHES). */
    private const array JSON_FLAG_SETS = [
        0,
        JSON_UNESCAPED_UNICODE,
        JSON_HEX_APOS | JSON_HEX_QUOT,
        JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
    ];

    /** @return list<string> each header as "Name: value", then the body when there is one */
    private static function lines(ResponseInterface $response): array
    {
        $lines = [];
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines[] = "{$name}: {$value}";
            }
        }
        $body = (string) $response->getBody();
        if ($body !== '') {
            $lines[] = $body;
        }

        return $lines;
    }

    /** Every header line and the body of $response, as one text. */
    private static function text(ResponseInterface $response): string
    {
        return implode("\n", self::lines($response));
    }

    private function assertEffect(string $expected, string $call): void
    {
        $effects = $this->effects();
        $this->assertStringContainsString(
            $expected,
            implode("\n", $effects),
            sprintf('%s shows "%s" in effects(), which lists: %s', $call, $expected, $effects === [] ? '(nothing)' : implode(' | ', $effects)),
        );
    }

    public function test_call_attributes_cover_every_neutral_option(): void
    {
        $driver = $this->driver;
        if (!$driver instanceof DrivesComponentActions) {
            $this->markTestSkipped('The driver does not drive component actions (DrivesComponentActions is optional).');
        }
        $url = '/--component/action?token=abc';
        $attributes = static fn (array $options, array $values = [], string $target = 'Box-1'): array
            => $driver->callAttributes(new ActionCall('post', $url, 'Box-1', $target, ActionOptions::from($options), $values));

        $base = $attributes([]);
        $this->assertNotSame([], $base);
        $joined = implode(' ', array_keys($base)) . ' ' . implode(' ', $base);
        $this->assertTrue(str_contains($joined, $url) || str_contains($joined, (string) json_encode($url, JSON_UNESCAPED_SLASHES)), 'the URL is in an attribute');
        $this->assertStringContainsString('Box-1', $joined, 'the target is in an attribute');
        $this->assertStringContainsStringIgnoringCase('post', $joined, 'the method is in an attribute');

        $changes = [
            'target' => [$attributes(['target' => 'results'], target: 'Box-1-results'), $attributes([], target: 'Box-1-results')],
            'swap' => [$attributes(['swap' => 'inner']), $base],
            'on' => [$attributes(['on' => 'change']), $base],
            'debounce' => [$attributes(['on' => 'input', 'debounce' => 300]), $attributes(['on' => 'input'])],
            'latest' => [$attributes(['latest' => true]), $base],
            'include' => [$attributes(['include' => 'filters']), $base],
            'disable' => [$attributes(['disable' => 'self']), $base],
            'scroll' => [$attributes(['scroll' => 'top']), $base],
            'values' => [$attributes([], ['page' => 2]), $base],
        ];
        foreach ($changes as $option => [$with, $without]) {
            $this->assertNotSame($without, $with, "the option {$option} changes the attributes");
        }
    }

    public function test_state_update_names_the_component(): void
    {
        $driver = $this->driver;
        if (!$driver instanceof DrivesComponentActions) {
            $this->markTestSkipped('The driver does not drive component actions (DrivesComponentActions is optional).');
        }

        foreach (['carrier' => $driver->stateCarrier(...), 'update' => $driver->stateUpdate(...)] as $what => $markup) {
            $html = $markup('Box-1', 'state-string-1');
            $this->assertStringContainsString('Box-1', $html, "the {$what} names the component");
            $this->assertStringContainsString('state-string-1', $html, "the {$what} holds the state");
            $this->assertNotSame($html, $markup('Box-2', 'state-string-1'), "the {$what} of another component differs");
            $this->assertStringNotContainsString('"<', $markup('Box-1', '"<'), "the {$what} escapes the state");
        }
    }
}
