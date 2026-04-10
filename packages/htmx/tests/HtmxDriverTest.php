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

    public function test_asset_tag_returns_script(): void
    {
        $tag = $this->driver->assetTag();

        $this->assertStringContainsString('<script', $tag);
        $this->assertStringContainsString('htmx.org', $tag);
    }
}
