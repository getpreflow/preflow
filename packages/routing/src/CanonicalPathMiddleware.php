<?php

declare(strict_types=1);

namespace Preflow\Routing;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Routing\Redirect\Redirect;
use Preflow\Routing\Redirect\RedirectPath;
use Preflow\Routing\Redirect\RedirectResolver;
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
 * - With a redirect entry for the clean path (exact or area, in the locale the address names) the
 *   answer is that entry's status and target in one response, not the clean 301 and then the
 *   entry's; the query is kept and the same Location rules apply. An entry that points back at
 *   the clean address itself, or at a protocol-relative Location, is skipped: the clean 301 runs.
 * - With LocaleUrls::$prefixDefault and $redirectBare a bare address (no locale segment) takes only an
 *   entry for every language here; a language's entry answers after the language redirect.
 * - With LocaleUrls::$homeSlash a prefixed locale's root keeps its slash ('/de' becomes '/de/').
 * - The 301 depends on the URL alone (no cookie, no Vary), so caches may keep it per URL.
 *
 * Register it first in the pipeline (prepend), before any middleware that reads the path,
 * e.g. the locale prefix, so '//de//about/' reaches '/de/about' in one hop.
 */
final class CanonicalPathMiddleware implements MiddlewareInterface
{
    private const CACHE_CONTROL = 'public, max-age=86400';

    /**
     * @param RedirectResolver|(\Closure(): ?RedirectResolver)|null $redirects the redirect lookup; a closure
     *        is asked on every request, because the store may be bound after boot
     * @param (\Closure(Redirect): void)|null $hit counts a redirect hit (not called for internal renders)
     * @param (\Closure(string $locale, string $path): bool)|null $pageExists whether a page (never a
     *        redirect entry) answers the clean path; when one does, the plain clean 301 is answered
     */
    public function __construct(
        private readonly ?NonPublicPaths $nonPublic = null,
        private readonly RedirectResolver|\Closure|null $redirects = null,
        private readonly ?LocaleUrls $urls = null,
        private readonly ?\Closure $hit = null,
        private readonly ?\Closure $pageExists = null,
    ) {}

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

    /**
     * Repeated slashes collapsed, trailing slash dropped (root stays '/'). With $urls whose
     * homeSlash is on, a prefixed locale's root keeps its slash ('/de/').
     */
    public static function canonical(string $path, ?LocaleUrls $urls = null): string
    {
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path === '') {
            return '/';
        }

        $trimmed = rtrim($path, "/");
        if ($trimmed === "") {
            return "/";
        }

        if ($urls !== null && $urls->homeSlash && $urls->isHome($trimmed . '/')) {
            return $trimmed . '/';
        }

        return $trimmed;
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
        $canonical = self::canonical($path, $this->urls);
        if ($canonical === $path || self::isEndpoint($canonical)) {
            return $handler->handle($request);
        }

        if ($this->nonPublic !== null && ($this->nonPublic->matches($path) || $this->nonPublic->matches($canonical))) {
            return $handler->handle($request);
        }

        return $this->oneHop($canonical, $query) ?? new Response(301, ['Location' => $canonical . ($query !== '' ? '?' . $query : '')]);
    }

    /**
     * The redirect entry's answer for the clean path, or null when there is none (or it cannot
     * be used safely): the caller then answers the plain clean 301. A failing store is no error.
     */
    private function oneHop(string $canonical, string $query): ?ResponseInterface
    {
        $resolver = $this->redirects instanceof \Closure ? ($this->redirects)() : $this->redirects;
        if ($resolver === null || $this->urls === null) {
            return null;
        }

        $locale = $this->urls->localeOf($canonical) ?? $this->urls->default;
        $path = RedirectPath::normalize($this->urls->strip($canonical));
        if ($path === null) {
            return null;
        }

        try {
            // A page wins over an entry: RedirectMiddleware only answers a 404.
            if ($this->pageExists !== null && ($this->pageExists)($locale, $path)) {
                return null;
            }

            [$entry, $rest] = $resolver->find($path, $locale);

            // On a site that sends every bare address through the language redirect, a bare
            // address may only take an entry for every language; a language's entry answers after it.
            if ($entry !== null && $entry->locale !== '' && $this->urls->prefixDefault && $this->urls->redirectBare
                && $this->urls->localeOf($canonical) === null) {
                return null;
            }

            $url = $entry === null ? null : $resolver->url($entry, $rest, $locale);
        } catch (\Throwable) {
            return null;
        }

        if ($entry === null || $url === null) {
            return null;
        }

        $location = $query === '' ? $url : RedirectResolver::withQuery($url, $query);

        // A protocol-relative Location would send the visitor to another host; a target equal
        // to the clean address would loop (RedirectMiddleware's visited-address rule).
        if (str_starts_with($location, '//') || str_starts_with($location, '/\\') || self::isAddress($url, $canonical)) {
            return null;
        }

        if ($this->hit !== null) {
            try {
                ($this->hit)($entry);
            } catch (\Throwable) {
                // a hit that cannot be counted never blocks the redirect
            }
        }

        return new Response($entry->status, ['Location' => $location, 'Cache-Control' => self::CACHE_CONTROL]);
    }

    private static function isAddress(string $url, string $canonical): bool
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host'])) {
            return false;
        }

        $target = RedirectPath::normalize($parts['path'] ?? '/');

        return $target !== null && $target === RedirectPath::normalize($canonical);
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
