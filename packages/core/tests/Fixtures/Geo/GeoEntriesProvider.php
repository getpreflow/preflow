<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Geo;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\Geo\GeoEntries;
use Preflow\View\Geo\GeoEntry;

/** A site's provider hooking into GeoEntries, as LlmsEndpointsTest configures it. */
final class GeoEntriesProvider extends ServiceProvider
{
    /** @var ?\Closure(string): iterable<GeoEntry> */
    public static ?\Closure $source = null;

    /** @var ?\Closure(string): array<string, mixed> */
    public static ?\Closure $site = null;

    public function register(Container $container): void
    {
        $entries = $container->get(GeoEntries::class);
        if (self::$source !== null) {
            $entries->source(self::$source);
        }
        if (self::$site !== null) {
            $entries->site(self::$site);
        }
    }
}
