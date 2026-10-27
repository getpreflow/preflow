<?php

declare(strict_types=1);

namespace Preflow\Members;

use Nyholm\Psr7\Response;
use Preflow\Data\Locales;
use Preflow\I18n\Translator;
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
    ) {}

    /**
     * Template variables every page gets (a page's own $vars win): locale, alternates, viewer,
     * layout, base (the member pages' URL: locale prefix and path), landing, register (the register link), password_min,
     * validity.confirm / validity.reset, privacy_url (config privacy_url in the page locale), and empty defaults for the form state (notice, error,
     * errors, old, next, next_query: "?next=..." for the links between login and register, ...), so a template never trips over an unset variable in debug mode.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars, ServerRequestInterface $request, int $status = 200): ResponseInterface
    {
        $locale = $request->getAttribute('locale');
        $locale = is_string($locale) && $this->locales->has($locale) ? $locale : $this->locales->default;
        // The locale middleware did this when the site has an i18n config; without one the
        // translator stays on the default locale, which is right for a one-language site.
        $this->translator->setLocale($locale);
        $this->pageLocale?->set($locale);

        $html = $this->engine->render('@members/' . $template . '.twig', $vars + [
            'locale' => $locale,
            'alternates' => $this->alternates($request),
            'viewer' => $this->viewer,
            'layout' => $this->config->layout(),
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

    /** For an htmx request: the browser navigates to $location; there is nothing to swap in. */
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
