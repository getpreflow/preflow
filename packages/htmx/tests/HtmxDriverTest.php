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
        $this->assertSame('<body hx-headers="js:x()">', $driver->carryHeaders('<body hx-headers="js:x()">', $headers), 'a script value is left alone');
        $this->assertSame('<body hx-headers={}>', $driver->carryHeaders('<body hx-headers={}>', $headers), 'an unquoted one too: never a second attribute');

        // The real <body>: not one in a comment or a script string; a quoted ">" in its attributes is fine.
        $page = '<head><!-- <body class="old"> --><script>var t = "<body>";</script></head><body data-x="a>b"><p>x</p></body>';
        $carried = $driver->carryHeaders($page, $headers);
        $this->assertStringContainsString('<!-- <body class="old"> --><script>var t = "<body>";</script></head><body data-x="a>b" hx-headers="', $carried);
        $this->assertSame(1, preg_match('# hx-headers="([^"]*)"#', $carried, $m));
        $this->assertSame($headers, json_decode(html_entity_decode($m[1], ENT_QUOTES), true));
    }
}
