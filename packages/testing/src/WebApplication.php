<?php

declare(strict_types=1);

namespace Preflow\Testing;

use Preflow\Core\Application;
use Preflow\Core\PageCache\PageCacheConfig;

/**
 * A site's app booted as the web serves it (not as the console), for tests of what only web
 * requests do: the page cache serves and stores. Its page cache verifies every page it stores
 * (page_cache.verify 'always'): each is rendered a second time as another anonymous visitor, and
 * a page that differs outside its live parts is not stored and is logged
 * ("page cache: {url} differs per visitor outside live parts"), so a personal value the site
 * forgot to put in a live part shows up in its suite.
 *
 *     $response = WebApplication::boot($basePath)->handle($request);
 *
 * Boot one per request, as the server does.
 */
final class WebApplication
{
    public static function boot(string $basePath, string $verify = PageCacheConfig::VERIFY_ALWAYS): Application
    {
        $app = Application::create($basePath);
        $app->setRunningInConsole(false);
        $app->setPageCacheVerify($verify);
        $app->boot();

        return $app;
    }
}
