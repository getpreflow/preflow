<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Psr\Http\Message\ServerRequestInterface;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;

final class Router implements RouterInterface
{
    private ?RouteCollection $collection = null;

    /**
     * @param string|null  $pagesDir    Path to pages directory for file-based routes
     * @param string[]     $controllers Controller class names for attribute-based routes
     * @param string|null  $cachePath   Path to compiled route cache file
     */
    public function __construct(
        private readonly ?string $pagesDir = null,
        private readonly array $controllers = [],
        private readonly ?string $cachePath = null,
    ) {}

    public function match(ServerRequestInterface $request): Route
    {
        $collection = $this->getCollection();
        $matcher = new RouteMatcher($collection);

        $method = $request->getMethod();
        $uri = $request->getUri()->getPath();

        $result = $matcher->match($method, $uri);

        if ($result === null) {
            throw new NotFoundHttpException("No route matches [{$method} {$uri}]");
        }

        $entry = $result['entry'];

        return new Route(
            mode: $entry->mode,
            handler: $entry->handler,
            parameters: $result['params'],
            middleware: $entry->middleware,
        );
    }

    public function getCollection(): RouteCollection
    {
        if ($this->collection !== null) {
            return $this->collection;
        }

        // Try loading from cache
        if ($this->cachePath !== null && file_exists($this->cachePath)) {
            $data = require $this->cachePath;
            $this->collection = RouteCollection::fromArray($data);
            return $this->collection;
        }

        // Build fresh
        $this->collection = new RouteCollection();

        if ($this->pagesDir !== null) {
            $fileScanner = new FileRouteScanner($this->pagesDir);
            $this->collection->addMany($fileScanner->scan());
        }

        $attrScanner = new AttributeRouteScanner();
        foreach ($this->controllers as $controller) {
            $this->collection->addMany($attrScanner->scanClass($controller));
        }

        return $this->collection;
    }
}
