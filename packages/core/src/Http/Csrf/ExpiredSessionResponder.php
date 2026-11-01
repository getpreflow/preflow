<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Nyholm\Psr7\Response;
use Preflow\Core\Http\OldInput;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The framework's answer to a POST whose session expired, when the site has no
 * ExpiredSessionHandler of its own. The request is never applied: nothing runs in the
 * visitor's name (a remember-me cookie could sign them in again), so the data waits for
 * one more click from a page with a fresh token. A form is sent back with a warning and
 * its fields kept as old input; htmx reloads the page; JSON clients get a 409.
 */
final class ExpiredSessionResponder
{
    public const string TRANSLATION_KEY = 'csrf.session_expired';

    private const array MESSAGES = [
        'en' => 'Your session had expired. Please send the form again.',
        'de' => 'Deine Sitzung war abgelaufen. Bitte sende das Formular noch einmal.',
        'fr' => 'Votre session avait expiré. Veuillez renvoyer le formulaire.',
        'it' => 'La sessione era scaduta. Invia di nuovo il modulo.',
    ];

    private readonly ?string $appOrigin;

    /**
     * @param string    $defaultLocale app.locale, when the request carries none
     * @param ?\Closure $translator    fn(): ?\Preflow\I18n\Translator; a site's text for
     *                                 csrf.session_expired wins over the built-in one
     * @param ?string   $appUrl        app.url: its origin counts as this site's (behind a TLS proxy
     *                                 the request itself looks like http://...)
     */
    public function __construct(
        private readonly string $defaultLocale = 'en',
        private readonly ?\Closure $translator = null,
        ?string $appUrl = null,
    ) {
        $this->appOrigin = $appUrl !== null ? OriginCheck::normalize($appUrl) : null;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $htmx = $request->getHeaderLine('HX-Request') === 'true';

        if (!$htmx && str_contains(strtolower($request->getHeaderLine('Accept')), 'application/json')) {
            return new Response(409, ['Content-Type' => 'application/json'], json_encode(['error' => 'session_expired'], JSON_THROW_ON_ERROR));
        }

        $session = $request->getAttribute(SessionInterface::class);
        if ($session instanceof SessionInterface) {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                OldInput::keep($session, $body);
            }
            $session->flash('warning', $this->message($request));
        }

        if ($htmx) {
            return new Response(204, ['HX-Refresh' => 'true']);
        }

        return new Response(303, ['Location' => $this->back($request)]);
    }

    private function message(ServerRequestInterface $request): string
    {
        $locale = $request->getAttribute('locale');
        $locale = is_string($locale) && $locale !== '' ? $locale : $this->defaultLocale;

        $translator = $this->translator !== null ? ($this->translator)() : null;
        if ($translator !== null && $translator->has(self::TRANSLATION_KEY, $locale)) {
            return $translator->get(self::TRANSLATION_KEY, [], $locale);
        }

        $language = strtolower(strtok($locale, '-_') ?: $locale);

        return self::MESSAGES[$language] ?? self::MESSAGES['en'];
    }

    /** The Referer's path and query when it is a page of this site (app.url or the request's origin), else the home page. */
    private function back(ServerRequestInterface $request): string
    {
        $referer = $request->getHeaderLine('Referer');
        $source = OriginCheck::normalize($referer);
        $own = OriginCheck::normalize((string) $request->getUri()->withPath('')->withQuery('')->withFragment(''));
        if ($source === null || ($source !== $own && $source !== $this->appOrigin)) {
            return '/';
        }

        $path = (string) parse_url($referer, PHP_URL_PATH);
        $query = (string) parse_url($referer, PHP_URL_QUERY);
        // "//host/..." (or "/\host", which browsers read the same) would leave the site.
        $path = '/' . ltrim($path, '/\\');

        return $path . ($query !== '' ? '?' . $query : '');
    }
}
