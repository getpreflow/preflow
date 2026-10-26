<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** The admin's 403: a page in the admin layout, or for htmx a message the toast shows. */
final class Forbidden
{
    /** The English text (folio.errors.forbidden); responses carry it in the user's interface language. */
    public const string MESSAGE = "You don't have access to this.";

    public static function response(ServerRequestInterface $request, TemplateEngineInterface $engine, string $prefix, TypeCatalog $catalog): ResponseInterface
    {
        $message = FolioText::get('folio.errors.forbidden');
        // A fetch() (preview, relation label) gets a short answer, not an admin page.
        if ($request->getHeaderLine('Sec-Fetch-Dest') === 'empty' && $request->getHeaderLine('HX-Request') !== 'true') {
            return new Response(403, ['Content-Type' => 'text/plain; charset=UTF-8'], $message);
        }
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return new Response(403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'HX-Trigger' => (string) json_encode(['folio:error' => ['message' => $message]]),
            ], $message);
        }

        return new Response(403, ['Content-Type' => 'text/html; charset=UTF-8'], $engine->render('@folio/admin/forbidden.twig', [
            'prefix' => $prefix,
            'types' => $catalog->all(),
            'message' => $message,
            // Inside a drawer (asset picker, "New" related record): the drawer's own layout.
            'layout' => $request->getHeaderLine('Sec-Fetch-Dest') === 'iframe' || ($request->getQueryParams()['_drawer'] ?? null) === '1'
                ? '@folio/admin/_drawer_layout.twig'
                : '@folio/admin/_layout.twig',
        ]));
    }
}
