<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
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
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $path = (string) $request->getAttribute('path', '');
        $match = $this->resolver->match($path);

        if ($match === null) {
            throw new NotFoundHttpException();
        }

        $html = $this->engine->render(self::template($this->engine, $match->route), [
            'record' => $match->record->toArray(),
            'rendered' => $this->records->renderedMap($match->record),
            'type' => $match->route->type,
            'preview' => false,
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
