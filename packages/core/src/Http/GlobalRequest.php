<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The one place PHP's superglobals become a PSR-7 request: Application::run() and, for a failure
 * before any request frame exists, FailureGuard build the request here.
 *
 * @since 0.84.2
 */
final class GlobalRequest
{
    /**
     * @since 0.84.2
     * @throws \RuntimeException when nyholm/psr7 and nyholm/psr7-server are not installed
     */
    public static function create(): ServerRequestInterface
    {
        if (!class_exists(\Nyholm\Psr7\Factory\Psr17Factory::class)
            || !class_exists(\Nyholm\Psr7Server\ServerRequestCreator::class)) {
            throw new \RuntimeException('Install nyholm/psr7 and nyholm/psr7-server to use run().');
        }
        $factory = new \Nyholm\Psr7\Factory\Psr17Factory();

        return (new \Nyholm\Psr7Server\ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
    }
}
