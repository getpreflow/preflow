<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Folio\Content\FrontendResolver;
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
        $record = $this->resolver->resolve($path);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        $html = $this->engine->render('@folio/frontend/page.twig', [
            'record' => $record->toArray(),
            'rendered' => $this->records->renderedMap($record),
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }
}
