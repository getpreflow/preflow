<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

use Preflow\Core\Context\Deprecations;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Wrappers around each internal render (the SEO audit, GEO, a page cache rebuild, verify, a
 * preview). Before 0.81 a package with request-scoped state (a content locale, a current site)
 * added one that saved the state, called $next and put the state back.
 *
 * Since 0.81 every internal render runs in a render frame of its own under its own context
 * (Application::render()): what it collects stays in its frame, and what it reads comes from its
 * context. A scope added with the deprecated add() still runs around each internal render,
 * inside its frame.
 */
final class RenderScopes
{
    /** @var list<\Closure(ServerRequestInterface, \Closure(): ResponseInterface): ResponseInterface> */
    private array $scopes = [];

    /**
     * @param \Closure(ServerRequestInterface, \Closure(): ResponseInterface): ResponseInterface $scope
     *
     * @deprecated 0.81, removed in 0.83: read what the render needs from its context
     *             (Contexts::current(): a context facet, or a memo keyed on RenderContext::memoKey())
     */
    public function add(\Closure $scope): void
    {
        Deprecations::once('Preflow\View\Geo\RenderScopes::add() is deprecated since 0.81 and is removed in 0.83; use a context facet or RenderContext::memoKey() (each internal render has its own frame and context).');
        $this->scopes[] = $scope;
    }

    /** Handle a render inside every scope, the first added outermost. */
    public function run(ServerRequestInterface $request, \Closure $render): ResponseInterface
    {
        $run = $render;
        foreach (array_reverse($this->scopes) as $scope) {
            $next = $run;
            $run = static fn (): ResponseInterface => $scope($request, $next);
        }

        return $run();
    }
}
