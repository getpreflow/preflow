<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
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

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->render($request, $match->record, $match->route, false));
    }

    private function render(ServerRequestInterface $request, DynamicRecord $record, FrontendRoute $route, bool $preview): string
    {
        $requested = $request->getAttribute('locale');
        $locales = $record->getType()->locales;
        $locale = is_string($requested) && $locales->has($requested) ? $requested : $locales->default;
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

        return $this->engine->render(self::template($this->engine, $route), [
            'record' => $record->localized($locale)->toArray(),
            'rendered' => $this->records->renderedMap($record, $locale),
            'type' => $route->type,
            'preview' => $preview,
            'locale' => $locale,
            'alternates' => $alternates,
        ]);
    }

    /**
     * A valid share-preview link shows the record's working copy to anyone
     * holding it: the token must verify, its nonce must match the record's
     * current _share_token (rotating it revokes links), and the request path
     * must be the record's own URL. Anything else falls back to the public page.
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
