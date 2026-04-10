<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Routing\RouteMode;
use Preflow\Routing\Attributes\HttpMethod;
use Preflow\Routing\Attributes\Middleware;
use Preflow\Routing\Attributes\Route;

final class AttributeRouteScanner
{
    /**
     * @return RouteEntry[]
     */
    public function scanClass(string $className): array
    {
        $ref = new \ReflectionClass($className);

        // Class must have #[Route] attribute
        $routeAttrs = $ref->getAttributes(Route::class);
        if ($routeAttrs === []) {
            return [];
        }

        $routeAttr = $routeAttrs[0]->newInstance();
        $prefix = rtrim($routeAttr->path, '/');

        // Collect class-level middleware
        $classMiddleware = $routeAttr->middleware;
        foreach ($ref->getAttributes(Middleware::class) as $mwAttr) {
            $classMiddleware = array_merge($classMiddleware, $mwAttr->newInstance()->middleware);
        }

        $entries = [];

        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Find HttpMethod attribute on this method
            $httpMethodAttr = null;
            foreach ($method->getAttributes() as $attr) {
                $instance = $attr->newInstance();
                if ($instance instanceof HttpMethod) {
                    $httpMethodAttr = $instance;
                    break;
                }
            }

            if ($httpMethodAttr === null) {
                continue;
            }

            // Build full pattern
            $methodPath = $httpMethodAttr->path;
            if ($methodPath === '/') {
                $pattern = $prefix ?: '/';
            } else {
                $pattern = $prefix . '/' . ltrim($methodPath, '/');
            }

            // Collect method-level middleware
            $methodMiddleware = $classMiddleware;
            foreach ($method->getAttributes(Middleware::class) as $mwAttr) {
                $methodMiddleware = array_merge($methodMiddleware, $mwAttr->newInstance()->middleware);
            }

            // Extract params and build regex
            $paramNames = [];
            $regex = $this->buildRegex($pattern, $paramNames);

            $entries[] = new RouteEntry(
                pattern: $pattern,
                handler: $className . '@' . $method->getName(),
                method: $httpMethodAttr->method,
                mode: RouteMode::Action,
                middleware: $methodMiddleware,
                paramNames: $paramNames,
                regex: $regex,
            );
        }

        return $entries;
    }

    /**
     * @param string[] $paramNames
     */
    private function buildRegex(string $pattern, array &$paramNames): string
    {
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $matches) use (&$paramNames) {
            $name = $matches[1];
            $paramNames[] = $name;
            return '(?P<' . $name . '>[^/]+)';
        }, $pattern);

        return '#^' . $regex . '$#';
    }
}
