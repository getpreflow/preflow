<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Debug\DebugCollector;
use Preflow\Core\Http\RequestLimits;
use Preflow\DevTools\Http\DebugToolbarMiddleware;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class DebugToolbarMiddlewareTest extends TestCase
{
    private function makeHandler(ResponseInterface $response): RequestHandlerInterface
    {
        return new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }

    public function test_injects_toolbar_into_html_response(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<html><body><p>Hello</p></body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $html = (string) $result->getBody();
        $this->assertStringContainsString('preflow-debug-toolbar', $html);
        $this->assertStringContainsString('</body>', $html);
    }

    public function test_injects_once_before_the_last_body_tag(): void
    {
        // e.g. the inlined htmx build carries "</body>" in a string literal
        $body = '<html><body><script>var t = "<body>" + x + "</body>";</script><p>Hello</p></body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $result = (new DebugToolbarMiddleware(new DebugCollector()))->process(new ServerRequest('GET', '/'), $this->makeHandler($response));
        $html = (string) $result->getBody();

        $this->assertSame(1, substr_count($html, 'id="preflow-debug-toolbar"'));
        $this->assertStringContainsString('var t = "<body>" + x + "</body>";</script>', $html);
        $this->assertLessThan(strrpos($html, '</body>'), strpos($html, 'id="preflow-debug-toolbar"'));
        $this->assertGreaterThan(strpos($html, '<p>Hello</p>'), strpos($html, 'id="preflow-debug-toolbar"'));
    }

    public function test_does_not_inject_into_an_internal_render(): void
    {
        $body = '<html><body><p>Hello</p></body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $result = self::internally(fn () => (new DebugToolbarMiddleware(new DebugCollector()))->process(new ServerRequest('GET', '/'), $this->makeHandler($response)));

        $this->assertSame($body, (string) $result->getBody());
    }

    public function test_does_not_inject_into_json_response(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '{"status":"ok"}';
        $response = new Response(200, ['Content-Type' => 'application/json'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/api'), $handler);
        $html = (string) $result->getBody();
        $this->assertStringNotContainsString('preflow-debug-toolbar', $html);
        $this->assertSame($body, $html);
    }

    public function test_does_not_inject_without_body_tag(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<p>Fragment without body tag</p>';
        $response = new Response(200, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $html = (string) $result->getBody();
        $this->assertStringNotContainsString('preflow-debug-toolbar', $html);
    }

    public function test_preserves_status_code(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<html><body>Not found</body></html>';
        $response = new Response(404, ['Content-Type' => 'text/html'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/missing'), $handler);
        $this->assertSame(404, $result->getStatusCode());
    }

    public function test_preserves_headers(): void
    {
        $collector = new DebugCollector();
        $middleware = new DebugToolbarMiddleware($collector);
        $body = '<html><body>Test</body></html>';
        $response = new Response(200, ['Content-Type' => 'text/html', 'X-Custom' => 'value'], $body);
        $handler = $this->makeHandler($response);
        $result = $middleware->process(new ServerRequest('GET', '/'), $handler);
        $this->assertSame('value', $result->getHeaderLine('X-Custom'));
    }

    /**
     * A page rendered in a frame of its own (a whole-page preview) comes back with its own policy:
     * the toolbar's tags carry that policy's nonce, not the nonce of the request around it, so
     * they run under the page's CSP.
     */
    public function test_the_toolbar_carries_the_nonce_of_the_page_s_own_policy(): void
    {
        $body = '<html><body><script nonce="cGFnZS1ub25jZQ">page()</script></body></html>';
        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $header) {
            $response = new Response(200, [
                'Content-Type' => 'text/html',
                $header => "default-src 'self'; script-src 'self' 'nonce-cGFnZS1ub25jZQ'; style-src 'self' 'nonce-cGFnZS1ub25jZQ'",
            ], $body);
            $result = (new DebugToolbarMiddleware(new DebugCollector(), static fn (): string => 'YWRtaW4tbm9uY2U'))
                ->process(new ServerRequest('GET', '/admin/preview/page/post/1'), $this->makeHandler($response));
            $html = (string) $result->getBody();

            $this->assertStringContainsString('<style nonce="cGFnZS1ub25jZQ">', $html, $header);
            $this->assertStringContainsString('<script nonce="cGFnZS1ub25jZQ">', $html, $header);
            $this->assertStringNotContainsString('YWRtaW4tbm9uY2U', $html, "{$header}: the request's own nonce is not the page's");
            $this->assertStringContainsString('0 issues', $html, "{$header}: the page's script is checked against its own nonce");
        }
    }

    /** The toolbar takes each nonce from the directive that governs its tag, the enforced policy over the Report-Only trial. */
    public function test_script_and_style_nonces_follow_the_governing_directives(): void
    {
        $cases = [
            'separate style nonce' => [["Content-Security-Policy" => "script-src 'nonce-c2NyaXB0'; style-src 'nonce-c3R5bGU'"], 'c2NyaXB0', 'c3R5bGU'],
            'script-src-elem, uppercase keyword, comma-joined list' => [["Content-Security-Policy" => "img-src 'self', script-src 'self'; script-src-elem 'NONCE-ZWxlbQ'; style-src-elem 'nonce-ZWxlbQ',"], 'ZWxlbQ', 'ZWxlbQ'],
            'enforced over Report-Only' => [["Content-Security-Policy-Report-Only" => "default-src 'nonce-dHJpYWw'", "Content-Security-Policy" => "default-src 'nonce-ZW5mb3JjZWQ'"], 'ZW5mb3JjZWQ', 'ZW5mb3JjZWQ'],
            // script-src governs scripts and names no nonce: default-src's does not apply, the request's is used.
            'script-src without a nonce beside default-src with one' => [["Content-Security-Policy" => "default-src 'nonce-ZGVm'; script-src 'self'"], 'cmVxdWVzdA', 'ZGVm'],
        ];
        foreach ($cases as $case => [$headers, $script, $style]) {
            $response = new Response(200, ['Content-Type' => 'text/html'] + $headers, '<html><body></body></html>');
            $html = (string) (new DebugToolbarMiddleware(new DebugCollector(), static fn (): string => 'cmVxdWVzdA'))
                ->process(new ServerRequest('GET', '/'), $this->makeHandler($response))->getBody();

            $this->assertStringContainsString("<script nonce=\"{$script}\">", $html, $case);
            $this->assertStringContainsString("<style nonce=\"{$style}\">", $html, $case);
        }
    }

    /**
     * Two policies the page is sent with that allow no common nonce: no nonce of the page's lets its
     * inline script run, the toolbar falls back to the request's and lists the page's script as blocked.
     */
    public function test_policies_without_a_common_nonce_leave_the_page_s_script_blocked(): void
    {
        foreach (["script-src 'nonce-b25l', script-src 'nonce-dHdv'", "script-src 'nonce-b25l', script-src 'self'"] as $policy) {
            $response = new Response(200, ['Content-Type' => 'text/html', 'Content-Security-Policy' => $policy], '<html><body><script nonce="b25l">page()</script></body></html>');
            $html = (string) (new DebugToolbarMiddleware(new DebugCollector(), static fn (): string => 'cmVxdWVzdA'))
                ->process(new ServerRequest('GET', '/'), $this->makeHandler($response))->getBody();

            $this->assertStringContainsString('<script nonce="cmVxdWVzdA">', $html, $policy);
            $this->assertStringContainsString('script without nonce', $html, "{$policy}: the inspector reports the page's blocked script");
        }
    }

    /** A page without a policy of its own gets the request's (SecurityHeadersMiddleware adds it afterwards): the request's nonce. */
    public function test_a_page_without_a_policy_of_its_own_gets_the_request_s_nonce(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html'], '<html><body><p>Hello</p></body></html>');
        $result = (new DebugToolbarMiddleware(new DebugCollector(), static fn (): string => 'cmVxdWVzdA'))
            ->process(new ServerRequest('GET', '/'), $this->makeHandler($response));

        $this->assertStringContainsString('<script nonce="cmVxdWVzdA">', (string) $result->getBody());
    }

    /** What the render asked of PHP's limits is read from its frame (RequestLimits, LongRequest records it). */
    public function test_shows_the_request_limits_the_frame_recorded(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html'], '<html><body><p>Hello</p></body></html>');
        $handler = new class ($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $limits = \Preflow\Core\Context\Contexts::frame()->slot(RequestLimits::class, static fn (): RequestLimits => new RequestLimits());
                $limits->ignoresUserAbort = true;
                $limits->timeLimit(300, 30, true);
                $limits->timeLimit(120, 0, false);

                return $this->response;
            }
        };

        $html = (string) self::visiting(fn () => (new DebugToolbarMiddleware(new DebugCollector()))->process(new ServerRequest('GET', '/'), $handler))->getBody();

        $this->assertStringContainsString('<div class="pdt-section-title">Request limits</div>', $html);
        $this->assertStringContainsString('Time limit 300 s: raised from 30 s', $html);
        $this->assertStringContainsString('Time limit 120 s: kept, there is none', $html);
        $this->assertStringContainsString('Goes on when the browser closes the tab (ignore_user_abort)', $html);
    }

    public function test_no_request_limits_add_no_section(): void
    {
        $response = new Response(200, ['Content-Type' => 'text/html'], '<html><body><p>Hello</p></body></html>');

        $html = (string) self::visiting(fn () => (new DebugToolbarMiddleware(new DebugCollector()))->process(new ServerRequest('GET', '/'), $this->makeHandler($response)))->getBody();

        $this->assertStringContainsString('preflow-debug-toolbar', $html);
        $this->assertStringNotContainsString('Request limits', $html);
    }

    /** $fn run in a visitor's request frame. */
    private static function visiting(\Closure $fn): mixed
    {
        return \Preflow\Core\Context\Contexts::run(
            \Preflow\Core\Context\RenderContext::fromRequest(new ServerRequest('GET', 'http://localhost/'), new \Preflow\Core\Context\Site('http://localhost', ['en'], 'en'), new \Preflow\Core\Context\SystemClock()),
            $fn,
        );
    }

    /** $fn run as an internal render runs (the SEO audit: it leaves no trace); the purpose says it, not the request. */
    private static function internally(\Closure $fn): mixed
    {
        return \Preflow\Core\Context\Contexts::run(
            \Preflow\Core\Context\RenderContext::internal(\Preflow\Core\Context\Purpose::Audit, new \Preflow\Core\Context\Site('http://localhost', ['en'], 'en'), new \Preflow\Core\Context\SystemClock(), '/'),
            $fn,
        );
    }
}
