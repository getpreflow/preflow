<?php

declare(strict_types=1);

namespace Preflow\I18n;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LocaleMiddleware implements MiddlewareInterface
{
    /**
     * @param string[] $availableLocales
     * @param string   $urlStrategy 'prefix' | 'none'
     */
    public function __construct(
        private readonly Translator $translator,
        private readonly array $availableLocales,
        private readonly string $defaultLocale,
        private readonly string $urlStrategy = 'prefix',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->defaultLocale;
        $strippedRequest = $request;

        // 1. URL prefix detection (highest priority)
        if ($this->urlStrategy === 'prefix') {
            $result = $this->detectFromUrlPrefix($request);
            if ($result !== null) {
                $locale = $result['locale'];
                $strippedRequest = $result['request'];
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

        // Set locale cookie
        $response = $response->withHeader(
            'Set-Cookie',
            "locale={$locale}; Path=/; SameSite=Lax; HttpOnly"
        );

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
        $header = $request->getHeaderLine('Accept-Language');

        if ($header === '') {
            return null;
        }

        // Parse Accept-Language: de-DE,de;q=0.9,en;q=0.8
        $parts = explode(',', $header);
        $languages = [];

        foreach ($parts as $part) {
            $part = trim($part);
            $segments = explode(';', $part);
            $lang = strtolower(trim($segments[0]));
            $q = 1.0;

            if (isset($segments[1])) {
                if (preg_match('/q=([0-9.]+)/', $segments[1], $m)) {
                    $q = (float) $m[1];
                }
            }

            // Extract just the language code (e.g., de-DE → de)
            $shortLang = explode('-', $lang)[0];
            $languages[$shortLang] = max($languages[$shortLang] ?? 0, $q);
        }

        // Sort by quality
        arsort($languages);

        // Find first available match
        foreach (array_keys($languages) as $lang) {
            if (in_array($lang, $this->availableLocales, true)) {
                return $lang;
            }
        }

        return null;
    }
}
