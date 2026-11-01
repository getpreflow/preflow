<?php

declare(strict_types=1);

namespace Preflow\View\Geo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Wrappers around each internal GEO render. A package with request-scoped
 * state (a content locale, a current site) adds one that saves the state,
 * calls $next and puts the state back, so renders don't leak into each other
 * or into the request that asked for them.
 */
final class RenderScopes
{
    /** @var list<\Closure(ServerRequestInterface, \Closure(): ResponseInterface): ResponseInterface> */
    private array $scopes = [];

    /** @param \Closure(ServerRequestInterface, \Closure(): ResponseInterface): ResponseInterface $scope */
    public function add(\Closure $scope): void
    {
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
