<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\InternalRender;
use Preflow\Core\Http\IsHttps;
use Preflow\Core\Http\LocaleUrls;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\Http\SafeReturnPath;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleMiddleware implements MiddlewareInterface
{
    /** The locale prefix stripped from the current request's URL ('/de'), or ''. */
    private string $urlPrefix = '';

    /**
     * @param string[] $availableLocales
     * @param string   $urlStrategy 'prefix' | 'none'
     * @param LocaleUrls|null $urls with redirectBare on (and the prefix strategy), a bare
     *        page path ('/faq') answers 302 to the visitor's language ('/de/faq')
     * @param string   $cookieName the cookie the locale is read from and written to
     * @param (\Closure(string $locale, string $path): bool)|null $pageExists whether $path is a
     *        page in $locale; a bare path that is none is not redirected. Fails closed: without
     *        it no bare path is redirected, so redirect_bare acts only once the app supplies its page lookup
     * @param NonPublicPaths|null $nonPublic paths that are never redirected (admin, APIs)
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $availableLocales,
        private readonly string $defaultLocale,
        private readonly string $urlStrategy = 'prefix',
        private readonly bool $cookie = false,
        private readonly ?LocaleUrls $urls = null,
        private readonly string $cookieName = 'locale',
        private readonly ?\Closure $pageExists = null,
        private readonly ?NonPublicPaths $nonPublic = null,
    ) {}

    /**
     * The locale prefix the current request's URL carried ('/de'; '/en' when the
     * default locale was in the URL), or '' when it carried none. Only set while
     * process() runs; cleared on entry and restored when it returns or throws.
     */
    public function urlPrefix(): string
    {
        return $this->urlPrefix;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Cleared on entry, restored on the way out (returned or thrown): renders after
        // the response (deferred mail) and nested handles never see a stale prefix.
        $previous = $this->urlPrefix;
        $this->urlPrefix = '';

        try {
            return $this->localize($request, $handler);
        } finally {
            $this->urlPrefix = $previous;
        }
    }

    private function localize(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $switched = $this->switchLanguage($request);
        if ($switched !== null) {
            return $switched;
        }

        $locale = $this->defaultLocale;
        $strippedRequest = $request;
        $bare = false;

        // 1. URL prefix detection (highest priority)
        if ($this->urlStrategy === 'prefix') {
            $result = $this->detectFromUrlPrefix($request);
            if ($result !== null) {
                $locale = $result['locale'];
                $strippedRequest = $result['request'];
                $this->urlPrefix = '/' . $locale;
            } else {
                $bare = true;
            }
        }

        // 1b. A bare page path ('/faq') goes to the visitor's language. No cookie is written
        // here: the locale cookie is only set when the visitor picks a language. Without a page
        // lookup nothing changes at all (fails closed: no redirect, no Vary).
        $vary = false;
        if ($bare && $this->urls?->redirectBare && $this->pageExists !== null && $this->redirectable($request)) {
            $preferred = $this->detectFromCookie($request)
                ?? $this->detectFromAcceptLanguage($request)
                ?? $this->defaultLocale;
            $path = $request->getUri()->getPath();

            if ((!$this->urls->prefixDefault && $preferred === $this->defaultLocale)
                || !($this->pageExists)($preferred, $path)
            ) {
                // Served as it is, in the default locale; the outcome still depended on the
                // visitor's preference, so caches must keep it apart from the redirect.
                $vary = true;
            } else {
                $location = $this->urls->path($preferred, $path);
                $query = $request->getUri()->getQuery();

                return new Response(302, [
                    'Location' => $query !== '' ? $location . '?' . $query : $location,
                    'Vary' => 'Accept-Language, Cookie',
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }

        // 2. Cookie (if URL didn't match)
        if ($locale === $this->defaultLocale && $this->urlStrategy !== 'prefix') {
            $cookieLocale = $this->detectFromCookie($request);
            if ($cookieLocale !== null) {
                $locale = $cookieLocale;
            }
        }

        // 3. Accept-Language header (if nothing else matched)
        if ($locale === $this->defaultLocale && $this->urlStrategy !== 'prefix') {
            $headerLocale = $this->detectFromAcceptLanguage($request);
            if ($headerLocale !== null) {
                $locale = $headerLocale;
            }
        }

        // Set the locale
        $this->translator->setLocale($locale);

        // Add locale attribute to request
        $strippedRequest = $strippedRequest->withAttribute('locale', $locale);

        // Handle the request
        $response = $handler->handle($strippedRequest);

        if ($vary) {
            $response = $response->withAddedHeader('Vary', 'Accept-Language, Cookie');
        }

        // Remember the locale in a cookie only when enabled (off by default with the
        // prefix strategy, where the URL already carries it) and only when it changed. With
        // redirect_bare the switch endpoint is the only writer (an explicit pick, never a page view).
        if ($this->cookie && !$this->urls?->redirectBare && ($request->getCookieParams()[$this->cookieName] ?? null) !== $locale) {
            $response = $response->withHeader(
                'Set-Cookie',
                "{$this->cookieName}={$locale}; Path=/; SameSite=Lax; HttpOnly"
            );
        }

        return $response;
    }

    /**
     * GET /--locale/{code}?to=/path: the one place the locale cookie is written (an explicit
     * pick). Answers 302 to a site-relative target (else the locale's root), never cached.
     * Null (not handled) when redirect_bare is off, the method is not GET/HEAD, or the code is
     * not one of the site's locales.
     */
    private function switchLanguage(ServerRequestInterface $request): ?ResponseInterface
    {
        if (!$this->urls?->redirectBare || !$this->urls->prefixed
            || !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || preg_match('#^/--locale/([^/]+)$#', $request->getUri()->getPath(), $m) !== 1
        ) {
            return null;
        }

        $code = $m[1];
        if (!in_array($code, $this->urls->locales, true)) {
            return null;
        }

        parse_str($request->getUri()->getQuery(), $query);
        $to = $query['to'] ?? null;
        $location = SafeReturnPath::check(is_string($to) ? $to : null, ['/']) ?? $this->urls->path($code, '/');

        $cookie = "{$this->cookieName}={$code}; Path=/; Max-Age=31536000; SameSite=Lax; HttpOnly";
        if (IsHttps::request($request)) {
            $cookie .= '; Secure';
        }

        return new Response(302, [
            'Location' => $location,
            'Set-Cookie' => $cookie,
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** A visitor's GET or HEAD of what could be a public page (not an API, admin or component path). */
    private function redirectable(ServerRequestInterface $request): bool
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || InternalRender::is($request)) {
            return false;
        }

        $path = $request->getUri()->getPath();
        if ($this->nonPublic?->matches($path)) {
            return false;
        }

        return !str_starts_with(ltrim($path, '/'), '--');
    }

    /**
     * @return array{locale: string, request: ServerRequestInterface}|null
     */
    private function detectFromUrlPrefix(ServerRequestInterface $request): ?array
    {
        $path = $request->getUri()->getPath();

        // The site's rule decides when the app passes it: any configured code ('/de-ch/about').
        if ($this->urls !== null && $this->urls->prefixed) {
            $candidate = $this->urls->localeOf($path);
            if ($candidate === null || !in_array($candidate, $this->availableLocales, true)) {
                return null;
            }

            return [
                'locale' => $candidate,
                'request' => $request->withUri($request->getUri()->withPath($this->urls->strip($path))),
            ];
        }

        // Without LocaleUrls: two-letter codes (/xx or /xx/...)
        if (preg_match('#^/([a-z]{2})(?:/|$)#', $path, $matches)) {
            $candidate = $matches[1];

            if (in_array($candidate, $this->availableLocales, true)) {
                // Strip the locale prefix
                $newPath = substr($path, 3) ?: '/';
                $newUri = $request->getUri()->withPath($newPath);

                return [
                    'locale' => $candidate,
                    'request' => $request->withUri($newUri),
                ];
            }
        }

        return null;
    }

    private function detectFromCookie(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $candidate = $cookies[$this->cookieName] ?? null;

        if ($candidate !== null && in_array($candidate, $this->availableLocales, true)) {
            return $candidate;
        }

        return null;
    }

    private function detectFromAcceptLanguage(ServerRequestInterface $request): ?string
    {
        return AcceptLanguage::best($request->getHeaderLine('Accept-Language'), array_values($this->availableLocales));
    }
}
