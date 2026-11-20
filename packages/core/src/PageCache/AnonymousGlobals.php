<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Runs a render with PHP's superglobals as an anonymous visitor sees them, put back afterwards
 * (in a finally), so code that reads the visitor from the superglobals instead of the PSR
 * request renders for nobody in particular:
 *
 * - `$_COOKIE` empty, and none of its keys in `$_REQUEST`;
 * - `$_SERVER['REMOTE_ADDR']` the given address, without `HTTP_COOKIE` or the forwarded
 *   address headers (`HTTP_X_FORWARDED_FOR`, `HTTP_X_REAL_IP`, `HTTP_CLIENT_IP`, `HTTP_FORWARDED`);
 * - `$_SESSION` unset (a leftover outside an active session, or the session of the request
 *   that queued a rebuild);
 * - with $query (a rebuild), `$_GET` and `$_REQUEST` are that query, `$_POST` and `$_FILES` empty:
 *   nothing of the request that wrote remains.
 *
 * Since 0.81 every render whose purpose is internal (the SEO audit, GEO, a rebuild, verify, a
 * preview; Purpose::isInternal()) runs this way: the application registers adapter() once per
 * process with Contexts::onEnter(), and the frame swaps the superglobals when it opens and puts
 * them back when it closes. Packages never read the superglobals; the adapter is for site code
 * that still does. The page cache's verify render calls run() itself.
 */
final class AnonymousGlobals
{
    private const array FORWARDED = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_FORWARDED'];

    /** The name the adapter is registered under (Contexts::onEnter()). */
    public const string HOOK = 'preflow.anonymous_globals';

    /**
     * The superglobal adapter for Contexts::onEnter(): for a frame whose purpose is internal, the
     * superglobals are an anonymous visitor's at 127.0.0.1 while the frame is open (with the
     * query of the render's request in $_GET), and put back when it closes. A render that never
     * returns (a timeout, out of memory) still gets the visitor's $_SESSION back by a shutdown
     * function, which PHP runs before it writes the session: the visitor is never signed out by
     * an emptied session.
     *
     * @since 0.81.0
     *
     * @return \Closure(\Preflow\Core\Context\RenderContext, \Preflow\Core\Context\RenderFrame): ?\Closure
     */
    public static function adapter(): \Closure
    {
        // The restores of the frames open now, innermost last; the shutdown function runs what is left.
        $open = new \ArrayObject();
        $hooked = false;

        return static function (\Preflow\Core\Context\RenderContext $ctx) use ($open, &$hooked): ?\Closure {
            if (!$ctx->purpose->isInternal()) {
                return null;
            }
            if (!$hooked) {
                $hooked = true;
                register_shutdown_function(static function () use ($open): void {
                    foreach (array_reverse($open->getArrayCopy()) as $restore) {
                        $restore();
                    }
                    $open->exchangeArray([]);
                });
            }
            $restore = self::enter('127.0.0.1', $ctx->request?->request?->getQueryParams() ?? []);
            $open->append($restore);
            $at = array_key_last($open->getArrayCopy());

            return static function () use ($open, $restore, $at): void {
                $restore();
                $open->exchangeArray(array_slice($open->getArrayCopy(), 0, (int) $at));
            };
        };
    }

    /**
     * Runs $fn with the superglobals as an anonymous visitor's and puts them back afterwards.
     *
     * @template T
     * @param \Closure(): T $fn
     * @param array<string, mixed>|null $query the query of the render (rebuilds); null keeps $_GET and $_POST
     * @return T
     */
    public static function run(string $remoteAddr, \Closure $fn, ?array $query = null): mixed
    {
        $restore = self::enter($remoteAddr, $query);
        try {
            return $fn();
        } finally {
            $restore();
        }
    }

    /**
     * Swaps the superglobals for an anonymous visitor's now (as run() does) and returns the
     * closure that puts them back.
     *
     * @since 0.81.0
     *
     * @param array<string, mixed>|null $query the query of the render (rebuilds); null keeps $_GET and $_POST
     * @return \Closure(): void
     */
    public static function enter(string $remoteAddr, ?array $query = null): \Closure
    {
        $saved = [$_COOKIE, $_SERVER, $_REQUEST, $_GET, $_POST, $_FILES];
        $session = isset($_SESSION) ? [$_SESSION] : null;
        unset($_SESSION);
        foreach (array_keys($_COOKIE) as $name) {
            unset($_REQUEST[$name]);
        }
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = $remoteAddr;
        unset($_SERVER['HTTP_COOKIE']);
        foreach (self::FORWARDED as $name) {
            unset($_SERVER[$name]);
        }
        if ($query !== null) {
            $_GET = $query;
            $_POST = [];
            $_FILES = [];
            $_REQUEST = $query;
        }
        $done = false;

        return static function () use ($saved, $session, &$done): void {
            if ($done) {
                return;
            }
            $done = true;
            [$_COOKIE, $_SERVER, $_REQUEST, $_GET, $_POST, $_FILES] = $saved;
            if ($session !== null) {
                $_SESSION = $session[0];
            } else {
                unset($_SESSION);
            }
        };
    }
}
