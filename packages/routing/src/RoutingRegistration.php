<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Routing\PageExists as CorePageExists;
use Preflow\Routing\Redirect\PageExists;

/**
 * What preflow/routing sets up in an app itself (core calls register() when it wires the
 * router): its own names for core's contracts answer under core's names, so core asks only its
 * own. Today that is Redirect\PageExists: a lookup a site binds under it is core's PageExists too
 * (the bare-path redirect of config/i18n.php redirect_bare asks core's).
 *
 * @since 0.78
 */
final class RoutingRegistration
{
    /** Aliases once every provider ran (they bind the lookups). */
    public static function register(Application $app): void
    {
        $container = $app->container();
        $app->onProvidersBooted(static function () use ($container): void {
            self::alias($container);
        });
    }

    /**
     * A lookup bound under Redirect\PageExists answers under core's PageExists: bound there when
     * nothing is; asked together with the one there when both names hold different lookups (both are
     * then built now, at boot). Nothing bound stays unbound, so core still sees that nothing can tell.
     */
    public static function alias(Container $container): void
    {
        if (!$container->bound(PageExists::class)) {
            return;
        }
        if (!$container->bound(CorePageExists::class)) {
            $container->singleton(CorePageExists::class, static fn (Container $c): CorePageExists => $c->get(PageExists::class));

            return;
        }

        $core = $container->get(CorePageExists::class);
        $own = $container->get(PageExists::class);
        if ($core === $own) {
            return;
        }
        $container->instance(CorePageExists::class, new class ($core, $own) implements CorePageExists {
            public function __construct(
                private readonly CorePageExists $first,
                private readonly CorePageExists $second,
            ) {}

            public function exists(string $locale, string $path): bool
            {
                return $this->first->exists($locale, $path) || $this->second->exists($locale, $path);
            }
        });
    }
}
