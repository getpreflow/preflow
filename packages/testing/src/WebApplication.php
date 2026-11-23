<?php

declare(strict_types=1);

namespace Preflow\Testing;

use PHPUnit\Framework\Assert;
use Preflow\Core\Application;
use Preflow\Core\PageCache\Cacheability;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\PageCache\PageCacheConfig;
use Psr\Http\Message\ServerRequestInterface;

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
 *
 * Since 0.81 a request can carry the context it renders in, for that request only (the next
 * one is a plain visit again): at() renders it as of a time, withContext() derives its context
 * any other way. Both work with any Application's handle(), and with actingAs().
 *
 *     $app->handle(WebApplication::at(new \DateTimeImmutable('2026-03-01T09:00:00Z'), $request));
 *     $app->handle(WebApplication::withContext(static fn (RenderContext $c) => $c->in('de'), $request));
 */
final class WebApplication
{
    public static function boot(string $basePath, string $verify = PageCacheConfig::VERIFY_ALWAYS): Application
    {
        // A $_SESSION outside an active session is residue of earlier tests in this process, never a visitor's.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            unset($_SESSION);
        }
        $app = Application::create($basePath);
        $app->setRunningInConsole(false);
        $app->setPageCacheVerify($verify);
        $app->boot();

        return $app;
    }

    /**
     * Fails unless the page cache would store the page at $path: it renders it as the cache does,
     * once more as another anonymous visitor, and compares the two. The failure says why (the
     * differing part of the page, or the refusal such as a cookie, a status, a session) and what
     * usually fixes it, as `php preflow pages:why` does. Nothing is stored or served, and it works
     * with app.debug on.
     *
     *     WebApplication::assertPageCacheable(WebApplication::boot($basePath), '/club');
     *
     * @since 0.85.0
     */
    public static function assertPageCacheable(Application $app, string $path, ?string $locale = null): void
    {
        $result = $app->container()->get(Cacheability::class)->check($path, $locale);
        Assert::assertTrue(
            $result->cacheable,
            "The page cache would not store {$path}" . ($locale !== null ? " ({$locale})" : '') . ": {$result->reason}"
                . ($result->hint !== null ? "\nHint: {$result->hint}" : ''),
        );
    }

    /**
     * $request rendered as of $now: its context is frozen there (RenderContext::at()), so "now"
     * is $now for the whole request, and the page cache neither serves nor stores it. It works with
     * handle(); render() takes its context from its caller and ignores it.
     *
     * @since 0.81.0
     */
    public static function at(\DateTimeImmutable $now, ServerRequestInterface $request): ServerRequestInterface
    {
        return self::withContext(static fn (RenderContext $ctx): RenderContext => $ctx->at($now), $request);
    }

    /**
     * $request rendered in the context $derive makes of the one it would get
     * (Application::withDerivedContext()): another locale, working copies, a role view… Inside a
     * frame of something else (a preview, an audit) it is still never looser than that frame.
     * It works with handle(); render() takes its context from its caller and ignores it.
     *
     * @since 0.81.0
     * @param \Closure(RenderContext): RenderContext $derive
     */
    public static function withContext(\Closure $derive, ServerRequestInterface $request): ServerRequestInterface
    {
        return Application::withDerivedContext($request, static fn (RenderContext $ctx): RenderContext => $derive($ctx));
    }
}
