<?php

declare(strict_types=1);

namespace Preflow\Testing\Contract;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Preflow\Htmx\HtmlAttributes;
use Preflow\Htmx\HypermediaDriver;
use Preflow\Htmx\SwapStrategy;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What every HypermediaDriver does, as tests. Extend it, implement the three hooks, and run it
 * with PHPUnit:
 *
 *     final class MyDriverContractTest extends HypermediaDriverContract
 *     {
 *         private MyEffects $effects;
 *
 *         protected function createDriver(): HypermediaDriver
 *         {
 *             return new MyDriver($this->effects = new MyEffects());
 *         }
 *
 *         protected function fragmentRequest(ServerRequestInterface $request): ServerRequestInterface
 *         {
 *             return $request->withHeader('My-Request', 'true');
 *         }
 *
 *         protected function effects(): array
 *         {
 *             return $this->effects->all();
 *         }
 *     }
 *
 * - createDriver() is called once per test (in setUp()).
 * - fragmentRequest() marks a request the way the driver's client marks the requests it sends
 *   (a header, a query parameter…).
 * - effects() lists what the driver recorded for the response (triggerEvent(), redirect(),
 *   pushUrl()) since createDriver(), as strings: for headers, "Name: value" lines work well.
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
 * - triggerEvent(), redirect() and pushUrl() show their event or URL in effects().
 */
abstract class HypermediaDriverContract extends TestCase
{
    /** The driver under test. */
    abstract protected function createDriver(): HypermediaDriver;

    /** $request, marked as one the driver's client sends (so isFragmentRequest() is true). */
    abstract protected function fragmentRequest(ServerRequestInterface $request): ServerRequestInterface;

    /**
     * The response-side effects the driver recorded since createDriver().
     *
     * @return list<string>
     */
    abstract protected function effects(): array;

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

    private function assertEffect(string $expected, string $call): void
    {
        $effects = $this->effects();
        $this->assertStringContainsString(
            $expected,
            implode("\n", $effects),
            sprintf('%s shows "%s" in effects(), which lists: %s', $call, $expected, $effects === [] ? '(nothing)' : implode(' | ', $effects)),
        );
    }
}
