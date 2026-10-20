<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The admin's 403: a page in the admin layout, or for htmx a message the toast shows. */
final class Forbidden
{
    public const string MESSAGE = "You don't have access to this.";

    public static function response(ServerRequestInterface $request, TemplateEngineInterface $engine, string $prefix, TypeCatalog $catalog): ResponseInterface
    {
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return new Response(403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'HX-Trigger' => (string) json_encode(['folio:error' => ['message' => self::MESSAGE]]),
            ], self::MESSAGE);
        }

        return new Response(403, ['Content-Type' => 'text/html; charset=UTF-8'], $engine->render('@folio/admin/forbidden.twig', [
            'prefix' => $prefix,
            'types' => $catalog->all(),
            'message' => self::MESSAGE,
        ]));
    }
}
