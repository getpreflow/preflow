<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\LocaleUrls;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Core\Routing\PageExists;
use Preflow\Core\Routing\Route;
use Preflow\Core\Routing\RouterInterface;
use Psr\Http\Message\ServerRequestInterface;

/** A router adapter that is not preflow/routing's and cannot say whether a page exists. */
class PlainRouter implements RouterInterface
{
    public function match(ServerRequestInterface $request): Route
    {
        throw new NotFoundHttpException('No route');
    }
}

/** A router adapter that answers core's PageExists itself: its pages are $paths. */
final class AnsweringRouter extends PlainRouter implements PageExists
{
    /** @param list<string> $paths */
    public function __construct(private readonly array $paths) {}

    public function exists(string $locale, string $path): bool
    {
        return in_array($path, $this->paths, true);
    }
}

/** A site's page lookup bound under the contract named in $binding (core's or routing's). */
final class PageLookupProvider extends ServiceProvider
{
    /** @var list<string> */
    public static array $paths = [];

    /** @var class-string */
    public static string $binding = PageExists::class;

    public function register(Container $container): void
    {
        $paths = self::$paths;
        $lookup = new class ($paths) implements \Preflow\Routing\Redirect\PageExists {
            /** @param list<string> $paths */
            public function __construct(private readonly array $paths) {}

            public function exists(string $locale, string $path): bool
            {
                return in_array($path, $this->paths, true);
            }
        };
        $container->instance(self::$binding, $lookup);
    }

    public static function reset(): void
    {
        self::$paths = [];
        self::$binding = PageExists::class;
    }
}
