<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\FolioText;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin's 403: a page in the admin layout, or for htmx a message the toast shows. Folio's
 * admin ships its own htmx (@folio/htmx.js), whatever the app's hypermedia driver is, so its
 * headers are read and written here directly.
 */
final class Forbidden
{
    /** The English text (folio.errors.forbidden); responses carry it in the user's interface language. */
    public const string MESSAGE = "You don't have access to this.";

    /**
     * @param string|null $message why, when it is not a missing permission (a read-only type, @since 0.78); null: the usual text
     * @param array{label: string, url: string}|null $back a way on instead of "ask an administrator" (the type's list, @since 0.78)
     */
    public static function response(ServerRequestInterface $request, TemplateEngineInterface $engine, string $prefix, TypeCatalog $catalog, ?string $message = null, ?array $back = null): ResponseInterface
    {
        $reason = $message !== null;
        $message ??= FolioText::get('folio.errors.forbidden');
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
            // "Ask an administrator" helps with a missing permission, not with what the type itself refuses.
            'help' => !$reason,
            'back' => $back,
            // Inside a drawer (asset picker, "New" related record): the drawer's own layout.
            'layout' => $request->getHeaderLine('Sec-Fetch-Dest') === 'iframe' || ($request->getQueryParams()['_drawer'] ?? null) === '1'
                ? '@folio/admin/_drawer_layout.twig'
                : '@folio/admin/_layout.twig',
        ]));
    }
}
