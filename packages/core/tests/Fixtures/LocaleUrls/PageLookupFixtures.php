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

/**
 * Site page lookups, each bound (as a singleton, built on first use) under the contract it names
 * (core's or routing's), with the paths it knows: $lookups in register(), $late inside an
 * onProvidersBooted hook (after every hook core and routing added). $built counts the lookups built.
 */
final class PageLookupProvider extends ServiceProvider
{
    /** @var array<class-string, list<string>> binding => paths */
    public static array $lookups = [];

    /** @var array<class-string, list<string>> binding => paths, bound in a later onProvidersBooted hook */
    public static array $late = [];

    public static int $built = 0;

    public function register(Container $container): void
    {
        self::bindAll($container, self::$lookups);
        $late = self::$late;
        if ($late !== []) {
            $container->get(\Preflow\Core\Application::class)->onProvidersBooted(static function () use ($container, $late): void {
                self::bindAll($container, $late);
            });
        }
    }

    /** @param array<class-string, list<string>> $lookups */
    private static function bindAll(Container $container, array $lookups): void
    {
        foreach ($lookups as $binding => $paths) {
            $container->singleton($binding, static function () use ($paths): \Preflow\Routing\Redirect\PageExists {
                self::$built++;

                return new class ($paths) implements \Preflow\Routing\Redirect\PageExists {
                    /** @param list<string> $paths */
                    public function __construct(private readonly array $paths) {}

                    public function exists(string $locale, string $path): bool
                    {
                        return in_array($path, $this->paths, true);
                    }
                };
            });
        }
    }

    public static function reset(): void
    {
        self::$lookups = [];
        self::$late = [];
        self::$built = 0;
    }
}
