<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Seo;

use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\PublicPage;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Seo\SeoSite;

/** A site's provider hooking into SeoSite and PublicPages during registration, as Folio does. */
final class SeoSiteProvider extends ServiceProvider
{
    /** @var ?\Closure(string): PageMeta */
    public static ?\Closure $defaults = null;

    /** @var list<string> */
    public static array $disallow = [];

    /** @var ?\Closure(string): iterable<PublicPage> */
    public static ?\Closure $pages = null;

    public function register(Container $container): void
    {
        $site = $container->get(SeoSite::class);
        if (self::$defaults !== null) {
            $site->defaults(self::$defaults);
        }
        foreach (self::$disallow as $path) {
            $site->disallow($path);
        }
        if (self::$pages !== null) {
            $container->get(PublicPages::class)->source(self::$pages);
        }
    }

    public static function reset(): void
    {
        self::$defaults = null;
        self::$disallow = [];
        self::$pages = null;
    }
}
