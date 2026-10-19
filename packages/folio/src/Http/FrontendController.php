<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\FrontendRoute;
use Preflow\Folio\Content\RecordRenderer;
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
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        $requested = $request->getAttribute('locale');
        $match = $this->resolver->match($path, is_string($requested) ? $requested : null);

        if ($match === null) {
            throw new NotFoundHttpException();
        }

        $record = $match->record;
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

        $html = $this->engine->render(self::template($this->engine, $match->route), [
            'record' => $record->localized($locale)->toArray(),
            'rendered' => $this->records->renderedMap($record, $locale),
            'type' => $match->route->type,
            'preview' => false,
            'locale' => $locale,
            'alternates' => $alternates,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
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
