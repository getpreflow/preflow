<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs an admin request in the user's interface language: the signed-in user's stored choice
 * (UserPreferences), else the default (folio.locale, else app.locale, else English). Signed-out
 * admin pages (sign-in, password) take the browser's language when it is offered. Admin
 * overrides are on for the request.
 *
 * The translator is shared with the frontend: on the way out, also when the handler throws,
 * the locale and the overrides state are put back as they were, so nothing reaches a later
 * public request. A preview inside the admin renders with asFrontend().
 *
 * After AdminAuthMiddleware on signed-in routes (the user is the request's Authenticatable).
 */
final class AdminLocaleMiddleware implements MiddlewareInterface
{
    /** Request attribute: the translator's locale before the admin request (the frontend's). */
    public const string FRONTEND_LOCALE = 'folio.frontend_locale';

    /** Request attribute: the interface locale of this admin request. */
    public const string LOCALE = 'folio.interface_locale';

    public function __construct(
        private readonly Translator $translator,
        private readonly InterfaceLocales $locales,
        private readonly UserPreferences $preferences,
        private readonly ?TemplateEngineInterface $engine = null,
        /** @var (\Closure(): void)|null run when an admin request starts (not a nested one): the overrides' cache reads afresh */
        private readonly ?\Closure $onStart = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->localeFor($request);
        $previousLocale = $this->translator->getLocale();
        $previousOverrides = $this->translator->usesOverrides();
        $previousCurrent = $this->locales->currentOrNull();
        if (!$previousOverrides && $this->onStart !== null) {
            ($this->onStart)();
        }

        $this->enter($locale, true, $locale);
        $previousText = \Preflow\Folio\FolioText::use($this->translator); // Folio's words in PHP (field types, notices)
        try {
            return $handler->handle($request
                ->withAttribute(self::FRONTEND_LOCALE, $request->getAttribute(self::FRONTEND_LOCALE) ?? $previousLocale)
                ->withAttribute(self::LOCALE, $locale));
        } finally {
            \Preflow\Folio\FolioText::use($previousText);
            $this->enter($previousLocale, $previousOverrides, $previousCurrent);
        }
    }

    /**
     * The locales offered for the interface.
     *
     * @return list<string>
     */
    public function interfaceLocales(): array
    {
        return $this->locales->all();
    }

    /**
     * Runs $render (a frontend template rendered inside an admin request, the page preview) in
     * the frontend's state: its locale, overrides off. Outside an admin request it just runs it.
     *
     * @template T
     * @param \Closure(): T $render
     * @return T
     */
    public static function asFrontend(Translator $translator, ServerRequestInterface $request, \Closure $render): mixed
    {
        $frontend = $request->getAttribute(self::FRONTEND_LOCALE);
        if (!is_string($frontend)) {
            return $render();
        }
        $locale = $translator->getLocale();
        $overrides = $translator->usesOverrides();
        $translator->setLocale($frontend);
        $translator->useOverrides(false);
        try {
            return $render();
        } finally {
            $translator->setLocale($locale);
            $translator->useOverrides($overrides);
        }
    }

    private function localeFor(ServerRequestInterface $request): string
    {
        $user = $request->getAttribute(Authenticatable::class);
        if (!$user instanceof Authenticatable) {
            return $this->locales->forBrowser($request->getHeaderLine('Accept-Language'));
        }
        $chosen = $this->preferences->locale($user->getAuthId());

        return $chosen !== null && $this->locales->offers($chosen) ? $chosen : $this->locales->default();
    }

    private function enter(string $locale, bool $overrides, ?string $current): void
    {
        $this->translator->setLocale($locale);
        $this->translator->useOverrides($overrides);
        $this->locales->setCurrent($current);
        $this->engine?->addGlobal('interface_locale', $this->locales->current());
    }
}
