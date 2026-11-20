<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Preflow\Auth\Authenticatable;
use Preflow\Core\Context\Contexts;
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
 * admin pages (sign-in, password) take the browser's language when it is offered.
 *
 * Since 0.81 it changes no service: the handler runs inside Contexts::within() on the admin
 * surface (RenderContext::admin(), overrides on), so the translator, FolioText, the validators
 * and InterfaceLocales::current() answer in the interface language for that stretch only. The
 * context's locale stays the content language. Afterwards (also when the handler throws) the
 * request's frame is on the surface it was on before, so work after the response (and a later
 * request) gets the visitor's texts and English validation, as before 0.81. A preview inside
 * the admin renders with asFrontend().
 *
 * Every admin route runs it, so it also hands the request the admin's 404 page (NotFound).
 *
 * After AdminAuthMiddleware on signed-in routes (the user is the request's Authenticatable).
 */
final class AdminLocaleMiddleware implements MiddlewareInterface
{
    /** Request attribute: the content locale of the admin request (the frontend's; the context's locale). */
    public const string FRONTEND_LOCALE = 'folio.frontend_locale';

    /** Request attribute: the interface locale of this admin request. */
    public const string LOCALE = 'folio.interface_locale';

    public function __construct(
        /** not used since 0.81.0 (the translator follows the render context); kept for the constructor's shape */
        private readonly Translator $translator,
        private readonly InterfaceLocales $locales,
        private readonly UserPreferences $preferences,
        /** not used since 0.81.0 (interface_locale is a lazy engine global); kept for the constructor's shape */
        private readonly ?TemplateEngineInterface $engine = null,
        /** @var (\Closure(): void)|null run when an admin request starts (not a nested one): the overrides' cache reads afresh */
        private readonly ?\Closure $onStart = null,
        /** not used since 0.81.0 (the admin surface translates validators); kept for the constructor's shape */
        private readonly ?\Preflow\Validation\ValidatorFactory $validators = null,
        /** the admin's 404 page, on the request for NotFound::response() (attribute NotFound::class) */
        private readonly ?NotFound $notFound = null,
    ) {}

    /** @throws \Preflow\Core\Context\NoRenderContext outside every render */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = $this->localeFor($request);
        $ctx = Contexts::current();
        // A request of its own, not one nested in another render (an admin page asked for in-process).
        if ($this->onStart !== null && Contexts::depth() === 1) {
            ($this->onStart)();
        }

        $request = $request
            ->withAttribute(self::FRONTEND_LOCALE, $request->getAttribute(self::FRONTEND_LOCALE) ?? $ctx->locale)
            ->withAttribute(self::LOCALE, $locale);
        if ($this->notFound !== null) {
            $request = $request->withAttribute(NotFound::class, $this->notFound);
        }

        return Contexts::within($ctx->admin($locale, overrides: true), static fn (): ResponseInterface => $handler->handle($request));
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
     * the frontend's texts: within the public surface in the request's content locale. Outside
     * an admin request it just runs it.
     *
     * @param Translator $translator not used since 0.81.0 (the translator follows the render context)
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

        return Contexts::within(Contexts::current()->public()->in($frontend), $render);
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
}
