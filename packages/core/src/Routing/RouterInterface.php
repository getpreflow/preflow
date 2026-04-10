<?php

declare(strict_types=1);

namespace Preflow\Core\Routing;

use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface
{
    /**
     * Match a request to a route.
     *
     * @throws \Preflow\Core\Exceptions\NotFoundHttpException If no route matches
     */
    public function match(ServerRequestInterface $request): Route;
}
