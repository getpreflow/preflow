<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\UndeclaredPermission;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\RecordRenderer;
use Preflow\Folio\Publishing\PreviewLinks;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class FrontendController
{
    public function __construct(
        private readonly FrontendResolver $resolver,
        private readonly TemplateEngineInterface $engine,
        private readonly RecordRenderer $records,
        private readonly ?ContentLocale $contentLocale = null,
        private readonly ?DataManager $dm = null,
        private readonly ?PreviewLinks $previews = null,
        private readonly ?\Preflow\Folio\Content\LiveContent $live = null,
        private readonly ?\Preflow\View\AssetCollector $assets = null,
        private readonly ?Access $access = null,
        private readonly bool $debug = false,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $shared = $this->sharedPreview($request);
        if ($shared !== null) {
            return $shared;
        }

        $path = (string) $request->getAttribute('path', '');
        $requested = $request->getAttribute('locale');
        $match = $this->resolver->match($path, is_string($requested) ? $requested : null);

        if ($match === null) {
            throw new NotFoundHttpException();
        }
        $refused = $this->gate($request, $match->record, $match->route);
        if ($refused !== null) {
            return $refused;
        }

        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($match->route->permission !== null) {
            $headers['Cache-Control'] = 'private, no-store';
        }

        return new Response(200, $headers, $this->render($request, $match->record, $match->route, false));
    }

    /**
     * A type with frontend.permission shows its pages only to users who have it.
     * Anyone else goes to the type's login with the requested path as "next", or
     * gets a 404 when there is no login. Undeclared permissions throw in debug and
     * are refused otherwise (even without auth configured, where Access allows all).
     */
    private function gate(ServerRequestInterface $request, DynamicRecord $record, FrontendRoute $route): ?ResponseInterface
    {
        $permission = $route->permission;
        if ($permission === null) {
            return null;
        }
        $registry = $this->access?->registry();
        $declared = $registry !== null && ($registry->isDeclared($permission) || $registry->disabledReason($permission) !== null);
        if (!$declared && $this->debug) {
            throw UndeclaredPermission::named($permission);
        }
        if ($declared && $this->access?->can($permission) === true) {
            return null;
        }

        $locale = $this->locale($request, $record);
        $login = $route->loginFor($locale, $record->getType()->locales->default);
        if ($login === null) {
            throw new NotFoundHttpException();
        }
        // The page's own URL (the locale prefix is already stripped from the request); always a path on this site, never "//host".
        $next = '/' . ltrim($this->resolver->urlFor($record, $locale) ?? $request->getUri()->getPath(), '/\\');

        return new Response(302, [
            'Location' => $login . (str_contains($login, '?') ? '&' : '?') . 'next=' . rawurlencode($next),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The page's locale: the requested one when the record's type has it, else the type's default. */
    private function locale(ServerRequestInterface $request, DynamicRecord $record): string
    {
        $requested = $request->getAttribute('locale');
        $locales = $record->getType()->locales;

        return is_string($requested) && $locales->has($requested) ? $requested : $locales->default;
    }

    private function render(ServerRequestInterface $request, DynamicRecord $record, FrontendRoute $route, bool $preview): string
    {
        $locales = $record->getType()->locales;
        $locale = $this->locale($request, $record);
        $this->contentLocale?->set($locale);

        // hreflang needs absolute URLs; 'url' stays relative for language switchers.
        $uri = $request->getUri();
        $origin = $uri->getHost() !== ''
            ? $uri->getScheme() . '://' . $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '')
            : '';
        $alternates = [];
        foreach ($locales->available as $code) {
            $url = $this->resolver->urlFor($record, $code);
            if ($url !== null) {
                $alternates[] = ['locale' => $code, 'url' => $url, 'href' => $origin . $url, 'default' => $code === $locales->default];
            }
        }

        $html = $this->engine->render(self::template($this->engine, $route), [
            'record' => $record->localized($locale)->toArray(),
            'rendered' => $this->records->renderedMap($record, $locale),
            'type' => $route->type,
            'preview' => $preview,
            'locale' => $locale,
            'alternates' => $alternates,
            'query' => $request->getQueryParams(),
        ]);

        // CSS collected while rendering (component styles, focal-point rules) goes into <head>.
        return $this->assets?->injectInto($html) ?? $html;
    }

    /**
     * A valid share-preview link shows the record's working copy to anyone
     * holding it: the token must verify, its nonce must match the record's
     * current _share_token (rotating it revokes links), and the request path
     * must be the record's own URL. Anything else falls back to the public page.
     * A gated type's link is for staff review: the reviewer must pass the gate too.
     */
    private function sharedPreview(ServerRequestInterface $request): ?ResponseInterface
    {
        $token = $request->getQueryParams()['folio_preview'] ?? null;
        if ($token === null) {
            parse_str($request->getUri()->getQuery(), $query);
            $token = $query['folio_preview'] ?? null;
        }
        if (!is_string($token) || $this->previews === null || $this->dm === null) {
            return null;
        }

        $target = $this->previews->verify($token);
        $route = $target !== null ? $this->resolver->routeFor($target->type) : null;
        $working = $target !== null && $route !== null ? $this->dm->findType($target->type, $target->id) : null;
        $nonce = $working?->get('_share_token');
        if ($working === null || !is_string($nonce) || !hash_equals($nonce, $target->nonce)) {
            return null;
        }

        $locale = $request->getAttribute('locale');
        $url = $this->resolver->urlFor($working, is_string($locale) ? $locale : $working->getType()->locales->default);
        if ($url === null || rtrim($url, '/') !== rtrim($request->getUri()->getPath(), '/')) {
            return null;
        }

        $refused = $this->gate($request, $working, $route);
        if ($refused !== null) {
            return $refused;
        }

        $this->live?->showWorkingCopies(); // the shared draft shows draft references too

        return new Response(200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
            'Cache-Control' => 'private, no-store',
        ], $this->render($request, $working, $route, true));
    }

    /** First existing template of the route's chain (userland, per-type, generic). */
    public static function template(TemplateEngineInterface $engine, FrontendRoute $route): string
    {
        foreach ($route->templates() as $template) {
            if ($engine->exists($template)) {
                return $template;
            }
        }

        return '@folio/frontend/page.twig';
    }
}
