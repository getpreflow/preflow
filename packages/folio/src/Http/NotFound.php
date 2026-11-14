<?php

declare(strict_types=1);

namespace Preflow\Folio\Http;

use Nyholm\Psr7\Response;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\View\TemplateEngineInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin's 404, the counterpart of Forbidden. A page the browser opened (a document request: not
 * htmx, not a fetch() in cors mode, and an Accept that names text/html) gets the ink "not found" page
 * in the admin layout, with the controller's message as a muted line and the path asked for. With
 * redirects.manage the path links to a new redirect from it (redirects/new?address=…).
 * Everything else (htmx swaps, fetch() callers, JSON clients, a static file, the public site) gets
 * the message as plain text, as before.
 *
 * Every 404 in src/Http goes through response() (NotFoundPageTest scans for others). The page needs
 * the admin's renderer: AdminLocaleMiddleware, which every admin route runs, puts it on the request
 * (attribute NotFound::class). A request without it (a static file, the site's own pages) never gets
 * the admin page.
 */
final class NotFound
{
    public function __construct(
        private readonly TemplateEngineInterface $engine,
        private readonly string $prefix,
        private readonly TypeCatalog $catalog,
        /** @var \Closure(string): bool whether the signed-in user has a permission (Access::can) */
        private readonly \Closure $can,
    ) {}

    public static function response(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $page = $request->getAttribute(self::class);
        if ($page instanceof self && self::isDocument($request)) {
            return $page->page($request, $message);
        }

        return new Response(404, ['Content-Type' => 'text/plain; charset=UTF-8'], $message);
    }

    /** A page the browser opened: not htmx, not a cors-mode fetch(), and an Accept naming text/html. */
    public static function isDocument(ServerRequestInterface $request): bool
    {
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return false;
        }
        if (strtolower($request->getHeaderLine('Sec-Fetch-Mode')) === 'cors') {
            return false;
        }
        foreach (explode(',', $request->getHeaderLine('Accept')) as $range) {
            if (strtolower(trim(explode(';', $range)[0])) === 'text/html') {
                return true;
            }
        }

        return false;
    }

    private function page(ServerRequestInterface $request, string $message): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $redirect = ($this->can)('redirects.manage')
            ? $this->prefix . '/redirects/new?address=' . rawurlencode($path)
            : null;

        return new Response(404, ['Content-Type' => 'text/html; charset=UTF-8'], $this->engine->render('@folio/admin/not-found.twig', [
            'prefix' => $this->prefix,
            'types' => $this->catalog->all(),
            'message' => $message,
            'path' => $path,
            'redirect_url' => $redirect,
            // Inside a drawer (asset picker, "New" related record): the drawer's own layout, as Forbidden.
            'layout' => $request->getHeaderLine('Sec-Fetch-Dest') === 'iframe' || ($request->getQueryParams()['_drawer'] ?? null) === '1'
                ? '@folio/admin/_drawer_layout.twig'
                : '@folio/admin/_layout.twig',
        ]));
    }
}
