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

/** Site page lookups, each bound under the contract it names (core's or routing's), with the paths it knows. */
final class PageLookupProvider extends ServiceProvider
{
    /** @var array<class-string, list<string>> binding => paths */
    public static array $lookups = [];

    public function register(Container $container): void
    {
        foreach (self::$lookups as $binding => $paths) {
            $container->instance($binding, new class ($paths) implements \Preflow\Routing\Redirect\PageExists {
                /** @param list<string> $paths */
                public function __construct(private readonly array $paths) {}

                public function exists(string $locale, string $path): bool
                {
                    return in_array($path, $this->paths, true);
                }
            });
        }
    }

    public static function reset(): void
    {
        self::$lookups = [];
    }
}
