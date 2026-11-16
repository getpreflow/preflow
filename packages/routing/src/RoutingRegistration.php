<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Application;
use Preflow\Core\Routing\PageLookups;
use Preflow\Routing\Redirect\PageExists;
use Preflow\Routing\Redirect\RedirectEntryExists;
use Preflow\Routing\Redirect\RedirectResolver;
use Preflow\Routing\Redirect\RedirectStore;
use Preflow\Routing\Redirect\RedirectTargets;

/**
 * What preflow/routing sets up in an app itself (core calls register() when it wires the
 * router): its own name for core's page lookup, Redirect\PageExists, is one of core's page lookup
 * sources (PageLookups). A lookup a site binds under it is asked, when a request needs to know,
 * beside one bound under core's PageExists; nothing is built at boot.
 *
 * Also its RedirectEntryExists, registered as an answer (PageLookups::addAnswer(), @since 0.78.2): the
 * bare redirect counts an address a redirect entry answers; no page lookup ever does. It looks the
 * store up per question, so a store bound after boot counts.
 *
 * @since 0.78
 */
final class RoutingRegistration
{
    public static function register(Application $app): void
    {
        $container = $app->container();
        $container->get(PageLookups::class)->add(PageExists::class);

        $container->singleton(RedirectEntryExists::class, static fn (): RedirectEntryExists => new RedirectEntryExists(
            static fn (): ?RedirectResolver => $container->bound(RedirectStore::class)
                ? new RedirectResolver(
                    $container->get(RedirectStore::class),
                    $container->bound(RedirectTargets::class) ? $container->get(RedirectTargets::class) : null,
                )
                : null,
        ));
        $container->get(PageLookups::class)->addAnswer(RedirectEntryExists::class);
    }
}
