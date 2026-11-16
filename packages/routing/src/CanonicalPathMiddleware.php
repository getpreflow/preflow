<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\NonPublicPaths;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers 301 for an address that differs from its clean form only by a trailing slash or
 * repeated slashes ('/faq/' and '//en//faq' become '/faq' and '/en/faq'; the root stays '/').
 * Opt-in through `canonical_paths` in config/routing.php (see enabled()); off, nothing changes.
 *
 * Safety:
 * - Only GET and HEAD are redirected; every other method passes through untouched.
 * - Location is site-relative and built from the normalised path alone, so it always starts
 *   with exactly one '/' ('//evil.example/x' becomes '/evil.example/x', never a host).
 * - The path is left alone (no redirect, handled as today) when normalising could change its
 *   meaning: a dot segment (raw or percent-encoded), a backslash (raw or %5C; browsers read
 *   '/\host' as '//host'), a percent-encoded slash (%2F), or a control byte. The same goes for
 *   a query string with control bytes. Percent-encoding is never decoded or re-encoded.
 * - Paths under NonPublicPaths (checked before and after normalising) are left alone, and so
 *   is every path whose first segment starts with '--' (framework endpoints such as
 *   '/--component' and '/--locale'), or whose second does behind a locale prefix
 *   ('/de/--component/x'), whether or not it is registered as non-public.
 * - Internal renders (InternalRender::is) are left alone: the app asked for that exact path.
 * - The 301 depends on the URL alone (no cookie, no Vary), so caches may keep it per URL.
 *
 * Register it first in the pipeline (prepend), before any middleware that reads the path,
 * e.g. the locale prefix, so '//de//about/' reaches '/de/about' in one hop.
 */
final class CanonicalPathMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?NonPublicPaths $nonPublic = null) {}

    /**
     * Whether config/routing.php turns canonical paths on. Default false.
     *
     * @throws \InvalidArgumentException when the file is not an array or the value is not a bool
     */
    public static function enabled(mixed $routingConfig): bool
    {
        if ($routingConfig === null) {
            return false;
        }

        if (!is_array($routingConfig)) {
            throw new \InvalidArgumentException('config/routing.php must return an array.');
        }

        $value = $routingConfig['canonical_paths'] ?? false;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException(
                'config/routing.php: canonical_paths must be true or false, got ' . get_debug_type($value) . '.',
            );
        }

        return $value;
    }

    /** Repeated slashes collapsed, trailing slash dropped (root stays '/'). */
    public static function canonical(string $path): string
    {
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path === '') {
            return '/';
        }

        $trimmed = rtrim($path, "/");

        return $trimmed === "" ? "/" : $trimmed;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();
        if (($method !== 'GET' && $method !== 'HEAD') || InternalRender::is($request)) {
            return $handler->handle($request);
        }

        $uri = $request->getUri();
        $path = $uri->getPath();
        $query = $uri->getQuery();

        if ($path === '' || $path[0] !== '/' || !$this->isUnambiguous($path) || self::hasControl($query)) {
            return $handler->handle($request);
        }

        // $path starts with '/' and canonical() collapses every run of slashes, so $canonical
        // starts with exactly one '/': the Location below can never name another host.
        $canonical = self::canonical($path);
        if ($canonical === $path || self::isEndpoint($canonical)) {
            return $handler->handle($request);
        }

        if ($this->nonPublic !== null && ($this->nonPublic->matches($path) || $this->nonPublic->matches($canonical))) {
            return $handler->handle($request);
        }

        return new Response(301, ['Location' => $canonical . ($query !== '' ? '?' . $query : '')]);
    }

    /**
     * A framework endpoint: the first segment starts with '--' ('/--locale/de'), or the second
     * does behind a locale prefix ('/de/--component/x'; any first segment counts, so no locale
     * list is needed here).
     */
    private static function isEndpoint(string $canonical): bool
    {
        $segments = explode('/', ltrim($canonical, '/'), 3);

        return str_starts_with($segments[0], '--') || str_starts_with($segments[1] ?? '', '--');
    }

    private function isUnambiguous(string $path): bool
    {
        if (self::hasControl($path) || str_contains($path, '\\') || preg_match('#%(?:5c|2f)#i', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            $decoded = str_replace('%2e', '.', strtolower($segment));
            if ($decoded === '.' || $decoded === '..') {
                return false;
            }
        }

        return true;
    }

    private static function hasControl(string $value): bool
    {
        return preg_match('/[\x00-\x1f\x7f]/', $value) === 1;
    }
}
