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

    /** @var list<string> the sets compiled live (not taken from the route store), in order */
    private array $liveSets = [];

    /**
     * @param string|null  $pagesDir    Path to pages directory for file-based routes
     * @param string[]     $controllers Controller class names for attribute-based routes
     * @param string|null  $cachePath   Path to compiled route cache file
     * @param (\Closure(string, \Closure(): list<array<string, mixed>>, list<string>): list<array<string, mixed>>)|null $store
     *        where compiled route sets are kept between requests (core passes the boot manifest's):
     *        called with a set's name, the closure that compiles its rows, and the files they come
     *        from; returns the stored rows, or the closure's. Null: every set is compiled live.
     */
    public function __construct(
        private readonly ?string $pagesDir = null,
        private readonly array $controllers = [],
        private readonly ?string $cachePath = null,
        /** File pages' extension: the template engine's ('twig', 'blade.php') */
        private readonly string $templateExtension = 'twig',
        private readonly ?\Closure $store = null,
    ) {}

    /**
     * One set of route entries (the app's own, or a package's), compiled once: from the route
     * store when it holds the set (`preflow optimize`'s boot manifest), else $entries() is run
     * and its result handed to the store. A package adds its routes with
     * `$router->getCollection()->addMany($router->compiled('vendor.name', fn () => [...]))`.
     *
     * The entries must depend only on code, config files and files that change on deploy (never on
     * stored data or the request); $sources names those files for `preflow optimize --check`. A set
     * the store can't keep (middleware given as closures or objects) is simply compiled live.
     *
     * @param \Closure(): iterable<RouteEntry> $entries
     * @param list<string>                     $sources absolute paths
     * @return list<RouteEntry>
     */
    public function compiled(string $set, \Closure $entries, array $sources = []): array
    {
        if ($this->store === null) {
            $this->liveSets[] = $set;
            $list = [];
            foreach ($entries() as $entry) {
                $list[] = $entry;
            }

            return $list;
        }
        $rows = ($this->store)($set, function () use ($set, $entries): array {
            $this->liveSets[] = $set;
            $rows = [];
            foreach ($entries() as $entry) {
                $rows[] = $entry->toArray();
            }

            return $rows;
        }, $sources);

        return array_map(RouteEntry::fromArray(...), $rows);
    }

    /**
     * The sets this router compiled live rather than taking them from the route store (with a
     * current boot manifest: none, until the admin's are added).
     *
     * @return list<string>
     */
    public function liveSets(): array
    {
        return $this->liveSets;
    }

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
            csrfExempt: $entry->csrfExempt,
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

        // The app's own: file pages, then controllers (compiled once with a route store)
        $this->collection = new RouteCollection();
        $this->collection->addMany($this->compiled('app', function (): array {
            $entries = [];
            if ($this->pagesDir !== null) {
                $entries = (new FileRouteScanner($this->pagesDir, $this->templateExtension))->scan();
            }
            $attrScanner = new AttributeRouteScanner();
            foreach ($this->controllers as $controller) {
                array_push($entries, ...$attrScanner->scanClass($controller));
            }

            return $entries;
        }, $this->pagesDir !== null ? [$this->pagesDir] : []));

        return $this->collection;
    }
}
