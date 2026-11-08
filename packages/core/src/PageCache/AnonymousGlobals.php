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
 * Used by verify (the second render, as another visitor) and by every rebuild.
 */
final class AnonymousGlobals
{
    private const array FORWARDED = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_FORWARDED'];

    /**
     * @template T
     * @param \Closure(): T $fn
     * @param array<string, mixed>|null $query the query of the render (rebuilds); null keeps $_GET and $_POST
     * @return T
     */
    public static function run(string $remoteAddr, \Closure $fn, ?array $query = null): mixed
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
        try {
            return $fn();
        } finally {
            [$_COOKIE, $_SERVER, $_REQUEST, $_GET, $_POST, $_FILES] = $saved;
            if ($session !== null) {
                $_SESSION = $session[0];
            } else {
                unset($_SESSION);
            }
        }
    }
}
