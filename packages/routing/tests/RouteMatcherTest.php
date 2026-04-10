<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteCollection;
use Preflow\Routing\RouteEntry;
use Preflow\Routing\RouteMatcher;

final class RouteMatcherTest extends TestCase
{
    private function entry(
        string $pattern,
        string $method = 'GET',
        string $handler = 'handler',
        RouteMode $mode = RouteMode::Component,
        array $paramNames = [],
        string $regex = '',
        bool $isCatchAll = false,
    ): RouteEntry {
        if ($regex === '') {
            $regex = '#^' . preg_replace_callback('/\{(\w+)\}/', function ($m) use ($isCatchAll) {
                return $isCatchAll ? '(?P<' . $m[1] . '>.+)' : '(?P<' . $m[1] . '>[^/]+)';
            }, $pattern) . '$#';
        }
        return new RouteEntry($pattern, $handler, $method, $mode, [], $paramNames, $regex, $isCatchAll);
    }

    public function test_matches_static_route(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/about', handler: 'about.twig'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/about');

        $this->assertNotNull($result);
        $this->assertSame('about.twig', $result['entry']->handler);
        $this->assertSame([], $result['params']);
    }

    public function test_matches_dynamic_route(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/blog/{slug}', handler: 'blog/[slug].twig', paramNames: ['slug']));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/blog/hello-world');

        $this->assertNotNull($result);
        $this->assertSame('hello-world', $result['params']['slug']);
    }

    public function test_matches_multiple_params(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry(
            '/users/{userId}/posts/{postId}',
            handler: 'handler',
            paramNames: ['userId', 'postId'],
        ));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/users/42/posts/99');

        $this->assertSame('42', $result['params']['userId']);
        $this->assertSame('99', $result['params']['postId']);
    }

    public function test_matches_correct_http_method(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/posts', method: 'GET', handler: 'list'));
        $collection->add($this->entry('/posts', method: 'POST', handler: 'create'));

        $matcher = new RouteMatcher($collection);

        $getResult = $matcher->match('GET', '/posts');
        $this->assertSame('list', $getResult['entry']->handler);

        $postResult = $matcher->match('POST', '/posts');
        $this->assertSame('create', $postResult['entry']->handler);
    }

    public function test_returns_null_for_no_match(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/about'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/nonexistent');

        $this->assertNull($result);
    }

    public function test_returns_null_for_wrong_method(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/posts', method: 'GET'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('POST', '/posts');

        $this->assertNull($result);
    }

    public function test_static_routes_match_before_dynamic(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/blog/{slug}', handler: 'dynamic', paramNames: ['slug']));
        $collection->add($this->entry('/blog/featured', handler: 'static'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/blog/featured');

        // Static should match first
        $this->assertSame('static', $result['entry']->handler);
    }

    public function test_catch_all_route(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry(
            '/docs/{path}',
            handler: 'docs/[...path].twig',
            paramNames: ['path'],
            isCatchAll: true,
        ));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/docs/getting-started/installation');

        $this->assertNotNull($result);
        $this->assertSame('getting-started/installation', $result['params']['path']);
    }

    public function test_catch_all_matches_after_specific(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/docs/api', handler: 'specific'));
        $collection->add($this->entry(
            '/docs/{path}',
            handler: 'catch-all',
            paramNames: ['path'],
            isCatchAll: true,
        ));

        $matcher = new RouteMatcher($collection);

        $specific = $matcher->match('GET', '/docs/api');
        $this->assertSame('specific', $specific['entry']->handler);

        $catchAll = $matcher->match('GET', '/docs/some/deep/path');
        $this->assertSame('catch-all', $catchAll['entry']->handler);
    }

    public function test_trailing_slash_normalized(): void
    {
        $collection = new RouteCollection();
        $collection->add($this->entry('/about'));

        $matcher = new RouteMatcher($collection);
        $result = $matcher->match('GET', '/about/');

        $this->assertNotNull($result);
    }
}
