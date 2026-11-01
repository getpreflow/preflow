<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Geo;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\View\Geo\RenderScopes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RenderScopesTest extends TestCase
{
    public function test_scopes_wrap_the_render_first_added_outermost(): void
    {
        $scopes = new RenderScopes();
        $log = [];
        foreach (['a', 'b'] as $name) {
            $scopes->add(function (ServerRequestInterface $request, \Closure $next) use ($name, &$log): ResponseInterface {
                $log[] = $name . ' in ' . $request->getUri()->getPath();
                $response = $next();
                $log[] = $name . ' out';

                return $response;
            });
        }

        $response = $scopes->run((new Psr17Factory())->createServerRequest('GET', '/about'), function () use (&$log): ResponseInterface {
            $log[] = 'render';

            return new Response(204);
        });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['a in /about', 'b in /about', 'render', 'b out', 'a out'], $log);
    }

    public function test_without_scopes_the_render_runs_as_is(): void
    {
        $response = (new RenderScopes())->run((new Psr17Factory())->createServerRequest('GET', '/'), fn () => new Response(201));

        $this->assertSame(201, $response->getStatusCode());
    }
}
