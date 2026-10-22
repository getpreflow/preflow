<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension\Screen;

use Nyholm\Psr7\Response;
use Preflow\Core\Container\Container;
use Preflow\Core\Http\Csrf\CsrfToken;
use Preflow\Core\Routing\Route;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The last route middleware of every screen route, and the one that answers: it builds
 * the screen class from the container, calls the route's method with the request (route
 * parameters as attributes, like core's action dispatch) and turns a ScreenView into the
 * admin page. Core's dispatcher is never reached, so the route handler stays the screen's
 * own "Class@method" for the permission check.
 */
final class ScreenDispatcher implements MiddlewareInterface
{
    public function __construct(
        private readonly Container $container,
        private readonly ScreenRegistry $screens,
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(Route::class);
        [$class, $method] = $route instanceof Route ? explode('@', $route->handler, 2) + [1 => ''] : ['', ''];
        $screen = $this->screens->get($class);
        if ($screen === null) {
            return $handler->handle($request);
        }
        foreach ($route->parameters as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $result = $this->container->get($class)->{$method}($request);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if ($result instanceof ScreenView) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $result->render($this->engine, [
                'prefix' => $this->prefix,
                'csrf' => $request->getAttribute(CsrfToken::class)?->getValue() ?? '',
                'screen' => ['label' => $screen['label'], 'path' => $screen['path']],
            ], "Screen {$class}::{$method}"));
        }
        if (is_string($result) || $result instanceof \Stringable) {
            return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], (string) $result);
        }

        throw new ExtensionException("Screen {$class}::{$method} must return a ScreenView, a response or a string, not " . get_debug_type($result) . '.');
    }
}
