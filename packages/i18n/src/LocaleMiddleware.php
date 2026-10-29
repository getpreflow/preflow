<?php

declare(strict_types=1);

namespace Preflow\I18n;

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
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $availableLocales,
        private readonly string $defaultLocale,
        private readonly string $urlStrategy = 'prefix',
        private readonly bool $cookie = false,
    ) {}

    /**
     * The locale prefix the current request's URL carried ('/de'; '/en' when the
     * default locale was in the URL), or '' when it carried none. Set anew by
     * every process() call, so a long-running worker never carries it over.
     */
    public function urlPrefix(): string
    {
        return $this->urlPrefix;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->urlPrefix = '';
        $locale = $this->defaultLocale;
        $strippedRequest = $request;

        // 1. URL prefix detection (highest priority)
        if ($this->urlStrategy === 'prefix') {
            $result = $this->detectFromUrlPrefix($request);
            if ($result !== null) {
                $locale = $result['locale'];
                $strippedRequest = $result['request'];
                $this->urlPrefix = '/' . $locale;
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

        // Remember the locale in a cookie only when enabled (off by default with the
        // prefix strategy, where the URL already carries it) and only when it changed.
        if ($this->cookie && ($request->getCookieParams()['locale'] ?? null) !== $locale) {
            $response = $response->withHeader(
                'Set-Cookie',
                "locale={$locale}; Path=/; SameSite=Lax; HttpOnly"
            );
        }

        return $response;
    }

    /**
     * @return array{locale: string, request: ServerRequestInterface}|null
     */
    private function detectFromUrlPrefix(ServerRequestInterface $request): ?array
    {
        $path = $request->getUri()->getPath();

        // Match /xx or /xx/...
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
        $candidate = $cookies['locale'] ?? null;

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
