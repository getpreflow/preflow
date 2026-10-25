<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal The Application's CsrfMiddleware is built before the service providers run, so
 * the site's ExpiredSessionHandler binding is looked up when an expired case occurs.
 */
final class ContainerExpiredSessionHandler implements ExpiredSessionHandler
{
    public function __construct(private readonly ContainerInterface $container) {}

    public function handle(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->container->has(ExpiredSessionHandler::class)) {
            return null;
        }
        $handler = $this->container->get(ExpiredSessionHandler::class);
        if (!$handler instanceof ExpiredSessionHandler || $handler instanceof self) {
            return null;
        }

        return $handler->handle($request);
    }
}
