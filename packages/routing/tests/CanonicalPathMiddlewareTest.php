<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Routing\CanonicalPathMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CanonicalPathMiddlewareTest extends TestCase
{
    private int $handled = 0;

    private function send(string $method, string $uri, ?NonPublicPaths $nonPublic = null): ResponseInterface
    {
        $this->handled = 0;
        $handler = new class ($this) implements RequestHandlerInterface {
            public function __construct(private readonly CanonicalPathMiddlewareTest $t) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->t->markHandled();

                return new Response(200, [], 'ok');
            }
        };

        return (new CanonicalPathMiddleware($nonPublic))->process($this->request($method, $uri), $handler);
    }

    /** The path is kept exactly as sent ('//x' stays a path; Nyholm's Uri would fold it into an authority). */
    private function request(string $method, string $target): ServerRequestInterface
    {
        [$path, $query] = array_pad(explode('?', $target, 2), 2, '');

        return $this->stubbed($path, $query)->withMethod($method);
    }

    private function stubbed(string $path, string $query): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        $uri->method('getQuery')->willReturn($query);

        return (new ServerRequest('GET', '/'))->withUri($uri);
    }

    public function markHandled(): void
    {
        $this->handled++;
    }

    public function test_trailing_slash_redirects_301(): void
    {
        $r = $this->send('GET', '/faq/');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/faq', $r->getHeaderLine('Location'));
        $this->assertSame(0, $this->handled);
    }

    public function test_root_is_left_alone(): void
    {
        $this->assertSame(200, $this->send('GET', '/')->getStatusCode());
    }

    public function test_repeated_slashes_collapse(): void
    {
        $this->assertSame('/en/faq', $this->send('GET', '//en//faq')->getHeaderLine('Location'));
    }

    public function test_only_slashes_become_root(): void
    {
        $r = $this->send('GET', '///');
        $this->assertSame(301, $r->getStatusCode());
        $this->assertSame('/', $r->getHeaderLine('Location'));
    }

    public function test_query_string_is_kept(): void
    {
        $this->assertSame('/faq?a=1&b=x//y/', $this->send('GET', '/faq/?a=1&b=x//y/')->getHeaderLine('Location'));
    }

    public function test_post_is_never_redirected(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $m) {
            $this->assertSame(200, $this->send($m, '/faq/')->getStatusCode(), $m);
        }
    }

    public function test_head_is_redirected(): void
    {
        $this->assertSame(301, $this->send('HEAD', '/faq/')->getStatusCode());
    }

    public function test_leading_double_slash_never_becomes_protocol_relative(): void
    {
        $r = $this->send('GET', '//evil.example/x/');
        $this->assertSame('/evil.example/x', $r->getHeaderLine('Location'));
        $r = $this->send('GET', '////evil.example');
        $this->assertSame('/evil.example', $r->getHeaderLine('Location'));
    }

    public function test_non_public_paths_are_left_alone(): void
    {
        $np = new NonPublicPaths();
        $np->add('/folio');
        $this->assertSame(200, $this->send('GET', '/folio/pages/', $np)->getStatusCode());
        $this->assertSame(200, $this->send('GET', '//folio/pages/', $np)->getStatusCode());
        $this->assertSame(200, $this->send('GET', '/--component/x/', $np)->getStatusCode());
    }

    public function test_double_dash_first_segment_is_left_alone_without_non_public_paths(): void
    {
        foreach (['/--locale/de/', '/--anything//x/', '//--locale/de/', '/--/'] as $path) {
            $r = $this->send('GET', $path);
            $this->assertSame(200, $r->getStatusCode(), $path);
            $this->assertSame(1, $this->handled, $path);
        }
    }

    public function test_internal_render_is_left_alone(): void
    {
        $request = InternalRender::mark($this->request('GET', '/faq/'));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $r = (new CanonicalPathMiddleware())->process($request, $handler);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getHeaderLine('Location'));
    }

    public function test_root_with_query_only_is_not_rewritten(): void
    {
        $r = $this->send('GET', '/?x');
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getHeaderLine('Location'));
        $this->assertSame(1, $this->handled);
    }

    public function test_clean_path_passes_through(): void
    {
        $this->assertSame(200, $this->send('GET', '/a/b?x=1')->getStatusCode());
        $this->assertSame(1, $this->handled);
    }

    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return [
            'dot segment' => ['/a/../b/'],
            'single dot' => ['/a/./b//'],
            'encoded dot segment' => ['//a/%2e%2E/b'],
            'backslash' => ['/\\evil.example/'],
            'backslash after slashes' => ['//\\evil.example'],
            'encoded backslash' => ['//a%5Cb'],
            'encoded slash' => ['//a%2Fb'],
            'control byte' => ["/a\x01b/"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_ambiguous_paths_are_left_alone(string $path): void
    {
        $request = $this->stubbed($path, '');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $r = (new CanonicalPathMiddleware())->process($request, $handler);
        $this->assertSame(200, $r->getStatusCode());
        $this->assertSame('', $r->getHeaderLine('Location'));
    }

    public function test_control_bytes_in_query_are_left_alone(): void
    {
        $request = $this->stubbed('/faq/', "a=1\r\nX: y");
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
        $this->assertSame(200, (new CanonicalPathMiddleware())->process($request, $handler)->getStatusCode());
    }

    public function test_redirect_is_not_vary_dependent_and_cacheable_by_url(): void
    {
        $r = $this->send('GET', '/faq/');
        $this->assertSame('', $r->getHeaderLine('Set-Cookie'));
        $this->assertSame('', $r->getHeaderLine('Vary'));
    }

    public function test_canonical(): void
    {
        $this->assertSame('/', CanonicalPathMiddleware::canonical('/'));
        $this->assertSame('/', CanonicalPathMiddleware::canonical(''));
        $this->assertSame('/a/b', CanonicalPathMiddleware::canonical('//a///b//'));
        $this->assertSame('/a%2F/b', CanonicalPathMiddleware::canonical('/a%2F//b'));
    }

    public function test_enabled_reads_the_routing_config(): void
    {
        $this->assertFalse(CanonicalPathMiddleware::enabled(null));
        $this->assertFalse(CanonicalPathMiddleware::enabled([]));
        $this->assertFalse(CanonicalPathMiddleware::enabled(['canonical_paths' => false]));
        $this->assertTrue(CanonicalPathMiddleware::enabled(['canonical_paths' => true]));
    }

    public function test_enabled_rejects_non_bool(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical_paths');
        CanonicalPathMiddleware::enabled(['canonical_paths' => 'yes']);
    }

    public function test_enabled_rejects_non_array_file(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CanonicalPathMiddleware::enabled('nope');
    }
}
