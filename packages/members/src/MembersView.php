<?php

declare(strict_types=1);

namespace Preflow\Members;

use Nyholm\Psr7\Response;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Deprecations;
use Preflow\Core\Http\OldInput;
use Preflow\Data\Locales;
use Preflow\I18n\Translator;
use Preflow\Members\Http\Input;
use Preflow\View\AssetCollector;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Renders the member pages (@members/{template}.twig, a site's resources/members/ first) in
 * the configured layout. Every answer is private and never cached: these pages carry tokens,
 * CSRF fields and a person's name.
 */
final class MembersView
{
    private const string CACHE = 'private, no-store';

    /**
     * @param Translator $translator unused since 0.81 (the page's texts follow the render's locale); kept so existing calls keep working
     * @param ?PageLocale $pageLocale deprecated since 0.81, removed in 0.83: an app's own one is still told the page's locale, inside the page's render
     */
    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly AssetCollector $assets,
        private readonly Viewer $viewer,
        private readonly MembersConfig $config,
        private readonly Links $links,
        private readonly LinkValidity $validity,
        private readonly Locales $locales,
        private readonly Translator $translator,
        private readonly ?PageLocale $pageLocale = null,
        private readonly ?OldInput $oldInput = null,
        private readonly ?\Preflow\Core\Http\Hypermedia\HypermediaProtocols $hypermedia = null,
    ) {}

    /**
     * Template variables every page gets (a page's own $vars win): locale, alternates, viewer,
     * layout, base (the member pages' URL: locale prefix and path), landing, register (the register link), password_min,
     * validity.confirm / validity.reset, privacy_url (config privacy_url in the page locale), warning (the session's "warning"
     * flash, e.g. a form sent back after an expired session), and empty defaults for the form state (notice, error,
     * errors, old, next, next_query: "?next=..." for the links between login and register, ...), so a template never trips over an unset variable in debug mode.
     *
     * Renders within Contexts::current()->in($locale) (since 0.81): the page's locale is the
     * render's for the page and no longer.
     *
     * @param array<string, mixed> $vars
     * @throws \Preflow\Core\Context\NoRenderContext outside every render
     */
    public function render(string $template, array $vars, ServerRequestInterface $request, int $status = 200): ResponseInterface
    {
        $locale = $request->getAttribute('locale');
        $locale = is_string($locale) && $this->locales->has($locale) ? $locale : $this->locales->default;

        // The page renders in its locale (texts, the site layout's content, the members' own
        // links); the render's locale is back afterwards, also when the page throws. Without an
        // i18n config the locale is the default one, which is right for a one-language site.
        return Contexts::within(Contexts::current()->in($locale), function () use ($template, $vars, $request, $status, $locale): ResponseInterface {
            if ($this->pageLocale !== null) {
                Deprecations::once('Preflow\Members\PageLocale::set() is deprecated since 0.81 and is removed in 0.83; use Contexts::current()->locale.');
                $this->pageLocale->set($locale);
            }

            return $this->page($template, $vars, $request, $status, $locale);
        });
    }

    /** @param array<string, mixed> $vars */
    private function page(string $template, array $vars, ServerRequestInterface $request, int $status, string $locale): ResponseInterface
    {
        $vars = $this->withFlashedInput($vars) + ['warning' => Input::flash($request, 'warning')];

        $html = $this->engine->render('@members/' . $template . '.twig', $vars + [
            'locale' => $locale,
            'alternates' => $this->alternates($request),
            'viewer' => $this->viewer,
            'layout' => $this->config->layout($this->engine),
            'base' => $this->links->local($locale),
            'landing' => $this->links->localize($locale, $this->config->landing()),
            'register' => $this->links->localize($locale, $this->config->register()),
            'password_min' => $this->config->passwordMin(),
            'validity' => ['confirm' => $this->validity->confirm($locale), 'reset' => $this->validity->reset($locale)],
            'notice' => '', 'error' => '', 'errors' => [], 'old' => [], 'old_email' => '', 'next' => '', 'next_query' => '',
            'email' => '', 'name' => '', 'token' => '', 'sent' => false, 'used' => false, 'open' => true,
            'privacy_url' => $this->config->privacyUrl($locale, $this->locales->default),
        ]);

        return new Response($status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => self::CACHE,
        ], $this->assets->injectInto($html));
    }

    /**
     * A form sent back after an expired session shows the fields it kept (old / old_email),
     * unless the controller passed its own.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function withFlashedInput(array $vars): array
    {
        $flashed = $this->oldInput?->all() ?? [];
        if ($flashed === []) {
            return $vars;
        }
        if (($vars['old'] ?? []) === []) {
            $vars['old'] = $flashed;
        }
        if (($vars['old_email'] ?? '') === '' && is_string($flashed['email'] ?? null)) {
            $vars['old_email'] = $flashed['email'];
        }

        return $vars;
    }

    /**
     * Send the browser to $location: a hypermedia client's request (htmx, the app's driver) is
     * told to navigate (there is nothing to swap in), any other request gets a 303.
     */
    public function navigate(\Psr\Http\Message\ServerRequestInterface $request, string $location): ResponseInterface
    {
        return $this->hypermedia?->navigate($request, new Response(200, ['Cache-Control' => self::CACHE]), $location)
            ?? $this->redirect($location);
    }

    /**
     * For an htmx request: the browser navigates to $location; there is nothing to swap in.
     *
     * @deprecated htmx only; navigate($request, $location) asks the client that sent the request.
     */
    public function hxRedirect(string $location): ResponseInterface
    {
        return new Response(200, ['HX-Redirect' => $location, 'Cache-Control' => self::CACHE]);
    }

    public function redirect(string $location): ResponseInterface
    {
        return new Response(303, ['Location' => $location, 'Cache-Control' => self::CACHE]);
    }

    /**
     * The current page in every locale. The locale middleware has already stripped the
     * prefix, so the request path is the same in all of them.
     *
     * @return list<array{locale: string, url: string, href: string, default?: bool}>
     */
    private function alternates(ServerRequestInterface $request): array
    {
        $uri = $request->getUri();
        $origin = $uri->getHost() === '' ? '' : $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        $path = $uri->getPath();
        $root = $this->links->local($this->locales->default);
        $rest = str_starts_with($path, $root) ? substr($path, strlen($root)) : '';

        // href is absolute (the layout and the hreflang links read it), url stays the path for the language switch.
        $alternates = [];
        foreach ($this->locales->available as $locale) {
            $url = $this->links->local($locale, $rest);
            $alternates[] = ['locale' => $locale, 'url' => $url, 'href' => $origin . $url] + ($locale === $this->locales->default ? ['default' => true] : []);
        }

        return $alternates;
    }
}
