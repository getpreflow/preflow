<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Authenticatable;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST {prefix}/_locale (the sidebar switch, the account page): stores the signed-in user's
 * interface language and goes back to the admin page it came from (the Referer, when it is
 * this site's and under the admin prefix), else the dashboard. Any signed-in user, without a
 * permission (FolioPermissionMiddleware::SIGNED_IN). A language not offered is refused (422).
 */
final class LocaleController
{
    public function __construct(
        private readonly UserPreferences $preferences,
        private readonly InterfaceLocales $locales,
        private readonly Translator $translator,
        private readonly string $prefix,
    ) {}

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(Authenticatable::class);
        if (!$user instanceof Authenticatable) {
            return new Response(302, ['Location' => $this->prefix . '/login']);
        }
        $locale = ((array) $request->getParsedBody())['locale'] ?? null;
        if (!is_string($locale) || !$this->locales->offers($locale)) {
            return new Response(422, ['Content-Type' => 'text/plain; charset=UTF-8'], $this->translator->get('folio.locale.not_offered'));
        }

        $this->preferences->setLocale($user->getAuthId(), $locale);

        return new Response(302, ['Location' => $this->back($request)]);
    }

    /** The referring admin page as a path (same origin, under the prefix), else the dashboard. */
    private function back(ServerRequestInterface $request): string
    {
        $dashboard = '/' . trim($this->prefix, '/');
        $referer = parse_url($request->getHeaderLine('Referer'));
        $uri = $request->getUri();
        if (!is_array($referer) || $uri->getHost() === '' || !isset($referer['host'], $referer['scheme'])) {
            return $dashboard;
        }
        $sameOrigin = strtolower($referer['scheme']) === strtolower($uri->getScheme())
            && strtolower($referer['host']) === strtolower($uri->getHost())
            && ($referer['port'] ?? null) === $uri->getPort();
        $path = (string) ($referer['path'] ?? '');
        if (!$sameOrigin || ($path !== $dashboard && !str_starts_with($path, $dashboard . '/')) || str_contains($path, '\\')) {
            return $dashboard;
        }

        return $path . (isset($referer['query']) ? '?' . $referer['query'] : '');
    }
}
