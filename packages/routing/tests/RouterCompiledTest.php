<?php

declare(strict_types=1);

namespace Preflow\Routing\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\RouteEntry;
use Preflow\Routing\RoutePattern;
use Preflow\Routing\Router;

/**
 * Router::compiled(): route sets compiled once into a store (core passes the boot manifest's),
 * served from it afterwards without compiling; without a store, or for a set the store can't
 * keep, compiled live.
 */
final class RouterCompiledTest extends TestCase
{
    private string $pagesDir = '';

    protected function setUp(): void
    {
        $this->pagesDir = sys_get_temp_dir() . '/preflow_router_compiled_' . bin2hex(random_bytes(4));
        mkdir($this->pagesDir . '/blog', 0755, true);
        file_put_contents($this->pagesDir . '/index.twig', '');
        file_put_contents($this->pagesDir . '/blog/[slug].twig', '');
    }

    protected function tearDown(): void
    {
        foreach (['index.twig', 'blog/[slug].twig'] as $file) {
            @unlink($this->pagesDir . '/' . $file);
        }
        @rmdir($this->pagesDir . '/blog');
        @rmdir($this->pagesDir);
    }

    public function test_sets_are_compiled_once_and_then_served_from_the_store(): void
    {
        $store = [];
        $calls = 0;
        $first = $this->router($store, $calls);
        $this->assertSame(['app', 'pkg'], $first->liveSets());
        $this->assertSame(1, $calls);
        $this->assertSame(['app', 'pkg'], array_keys($store));
        $this->assertSame(['/', '/blog/{slug}', '/pkg/{id}'], array_map(static fn (RouteEntry $e): string => $e->pattern, $first->getCollection()->all()));

        $second = $this->router($store, $calls);
        $this->assertSame([], $second->liveSets(), 'nothing compiled: both sets come from the store');
        $this->assertSame(1, $calls);
        $this->assertEquals($first->getCollection()->all(), $second->getCollection()->all());

        foreach (['/', '/blog/hello', '/blog/hello/', '/pkg/42', '/pkg/x', '/nope', '/blog'] as $path) {
            $this->assertSame($this->match($first, $path), $this->match($second, $path), $path);
        }
        $this->assertSame(['App\\Pkg@show', ['id' => '42']], $this->match($second, '/pkg/42'), 'the param constraint ([0-9]+) is kept');
        $this->assertSame('404', $this->match($second, '/pkg/x'));
    }

    public function test_without_a_store_every_set_is_compiled_live(): void
    {
        $router = new Router(pagesDir: $this->pagesDir);
        $entry = new RouteEntry('/a', 'A@b', 'GET', RouteMode::Action);
        $entries = $router->compiled('pkg', static fn (): array => [$entry]);
        $router->getCollection();

        $this->assertSame([$entry], $entries, 'the entries themselves: no store, no round trip');
        $this->assertSame(['pkg', 'app'], $router->liveSets());
    }

    public function test_a_set_the_store_cannot_keep_is_compiled_live_every_time(): void
    {
        // A store like the boot manifest's: it keeps only exportable rows (no closures).
        $kept = [];
        $store = static function (string $set, \Closure $compile) use (&$kept): array {
            if (isset($kept[$set])) {
                return $kept[$set];
            }
            $rows = $compile();
            $exportable = true;
            array_walk_recursive($rows, static function (mixed $v) use (&$exportable): void {
                $exportable = $exportable && !is_object($v);
            });
            if ($exportable) {
                $kept[$set] = $rows;
            }

            return $rows;
        };
        $middleware = static fn () => null;
        foreach ([1, 2] as $round) {
            $router = new Router(store: $store);
            $entries = $router->compiled('closures', static fn (): array => [new RouteEntry('/x', 'X@y', 'GET', RouteMode::Action, [$middleware])]);
            $this->assertSame([$middleware], $entries[0]->middleware, "round {$round}");
            $this->assertContains('closures', $router->liveSets(), "round {$round}: compiled live");
        }
        $this->assertArrayNotHasKey('closures', $kept);
    }

    public function test_entry_rows_round_trip(): void
    {
        $entry = new RouteEntry('/p/{id}', 'P@q', 'POST', RouteMode::Component, ['M'], ['id'], '#^/p/(?P<id>[^/]+)$#', false, true);

        $this->assertEquals($entry, RouteEntry::fromArray($entry->toArray()));
        $this->assertSame('component', $entry->toArray()['mode'], 'the mode as its value: plain arrays OPcache keeps in shared memory');
        $this->assertEquals($entry, RouteEntry::fromArray(eval('return ' . var_export($entry->toArray(), true) . ';')));
    }

    /** @param array<string, list<array<string, mixed>>> $store */
    private function router(array &$store, int &$calls): Router
    {
        $router = new Router(
            pagesDir: $this->pagesDir,
            store: static function (string $set, \Closure $compile) use (&$store): array {
                return $store[$set] ??= $compile();
            },
        );
        $router->getCollection()->addMany($router->compiled('pkg', static function () use (&$calls): array {
            $calls++;
            $c = RoutePattern::compile('/pkg/{id}');

            return [new RouteEntry('/pkg/{id}', 'App\\Pkg@show', 'GET', RouteMode::Action, [], $c['paramNames'], '#^/pkg/(?P<id>[0-9]+)$#', false)];
        }));

        return $router;
    }

    /** @return array{0: string, 1: array<string, string>}|string */
    private function match(Router $router, string $path): array|string
    {
        try {
            $route = $router->match((new Psr17Factory())->createServerRequest('GET', $path));
        } catch (NotFoundHttpException) {
            return '404';
        }

        return [$route->handler, $route->parameters];
    }
}
