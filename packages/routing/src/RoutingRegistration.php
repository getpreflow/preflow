<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Preflow\Core\Application;
use Preflow\Core\Routing\PageLookups;
use Preflow\Routing\Redirect\PageExists;

/**
 * What preflow/routing sets up in an app itself (core calls register() when it wires the
 * router): its own name for core's page lookup, Redirect\PageExists, is one of core's page lookup
 * sources (PageLookups). A lookup a site binds under it is asked, when a request needs to know,
 * beside one bound under core's PageExists; nothing is built at boot.
 *
 * @since 0.78
 */
final class RoutingRegistration
{
    public static function register(Application $app): void
    {
        $app->container()->get(PageLookups::class)->add(PageExists::class);
    }
}
