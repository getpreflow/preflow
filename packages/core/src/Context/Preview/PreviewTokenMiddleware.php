<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

use Nyholm\Psr7\Response;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\RenderContext;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Preflow\Core\Http\NonPublicPaths;
use Preflow\Core\Http\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Admits the follow-up requests of a staff member's preview (component actions and renders, lazy
 * fragments, a page's own htmx calls) that carry its preview token, as the query parameter
 * `_preview` or the X-Preflow-Preview header (PreviewUrls hands it on). Core adds it right after
 * auth's ResolveUserMiddleware, so the request's session and its real viewer are known.
 *
 * - No token: the request goes on untouched.
 * - A path that is not a public page (NonPublicPaths: the admin, APIs), except the component
 *   endpoint: the token is ignored (ruling R6; the admin is signed in there anyway, the token
 *   grants nothing more). The request goes on without it.
 * - A request already rendering under a grant (a request asked for inside a granted render) or
 *   an internal render: nothing more to grant; it goes on without the token.
 * - Otherwise PreviewToken::admit() checks it in the request's own frame (the session the request
 *   carries, its real viewer, the frame's clock, the PreviewGate). Any failure, whatever it throws
 *   (a rejection, an undeclared permission in debug mode, a broken signer), is answered 403 with a
 *   plain page, the handler is never called, and only the reason is logged (never the token).
 * - Admitted: the rest of the request renders in a frame of its own ($render: Contexts::run(),
 *   never narrow(), so a role view never replaces the request's viewer) under
 *   PreviewClaims::apply(): the visitor or the role viewed as, the token's locale and read mode,
 *   purpose Fragment, data writes refused, the outbox captured, a PreviewGrant. The request it
 *   gets carries no token, no session (the editor's session is never read, written or closed by
 *   the preview) and no user of the editor's ($viewerAttributes puts the frame's own on it). An
 *   error in it is answered here, with the request without the token, so no error page (the
 *   debug page either) shows it.
 *
 * Every answer to a request with a token (admitted or refused) is `Cache-Control: private,
 * no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`; the granted render's own
 * Set-Cookie headers are dropped (the cookies would land in the editor's browser), and an HTML
 * page gets the token handed to its hypermedia clients (PreviewUrls::inPage()). The page cache
 * neither serves nor stores such a request (PageCacheMiddleware, which runs before it).
 *
 * @since 0.81.0
 */
final class PreviewTokenMiddleware implements MiddlewareInterface
{
    /** The headers of every answer to a request that carries a token. */
    public const array HEADERS = [
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex',
    ];

    /**
     * @param \Closure(): PreviewGate $gate the gate admit() asks (resolved per request: an app's provider may bind its own)
     * @param \Closure(RenderContext, \Closure(): ResponseInterface): ResponseInterface $render runs the rest of the
     *        request in a frame of its own under the context (Contexts::run(), plus what the app shares with that frame)
     * @param \Closure(\Throwable, ServerRequestInterface): ResponseInterface $errors the error page for a failure inside the granted render
     * @param (\Closure(ServerRequestInterface): ServerRequestInterface)|null $viewerAttributes the request with the
     *        current frame's user attributes in place of the editor's (auth's); null: the request as it is
     * @param (\Closure(string): void)|null $log where rejections are reported (their reason only)
     * @param HypermediaProtocols|null $clients the page's hypermedia clients (PreviewUrls::inPage())
     */
    public function __construct(
        private readonly PreviewToken $tokens,
        private readonly \Closure $gate,
        private readonly NonPublicPaths $nonPublic,
        private readonly \Closure $render,
        private readonly \Closure $errors,
        private readonly ?\Closure $viewerAttributes = null,
        private readonly ?\Closure $log = null,
        private readonly ?HypermediaProtocols $clients = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $carried = self::carried($request);
        if ($carried === null) {
            return $handler->handle($request);
        }
        $plain = self::withoutToken($request);
        $path = $request->getUri()->getPath();
        $ctx = Contexts::current();
        if (($this->nonPublic->matches($path) && !self::isComponentPath($path))
            || $ctx->get(PreviewGrant::class) !== null
            || $ctx->purpose->isInternal()) {
            return $handler->handle($plain);
        }

        try {
            if ($carried === false) {
                throw new PreviewTokenRejected('malformed');
            }
            $session = $request->getAttribute(SessionInterface::class);
            $sessionId = $session instanceof SessionInterface && $session->isStarted() ? $session->getId() : '';
            $claims = $this->tokens->admit($carried, $sessionId, $ctx, ($this->gate)());
        } catch (\Throwable $e) {
            $this->report($e instanceof PreviewTokenRejected ? $e->reason : 'error (' . $e::class . ')');

            return self::withHeaders(self::refused());
        }

        $granted = $claims->apply($ctx);
        $inner = $plain->withoutAttribute(SessionInterface::class);
        $response = ($this->render)($granted->for($inner), function () use ($inner, $handler): ResponseInterface {
            $request = $inner->withAttribute(RenderContext::class, Contexts::current());
            if ($this->viewerAttributes !== null) {
                $request = ($this->viewerAttributes)($request);
            }
            try {
                return $handler->handle($request);
            } catch (\Throwable $e) {
                // Answered here, with the request without the token: no error page may print it.
                return ($this->errors)($e, $request);
            }
        });
        $response = $response->withoutHeader('Set-Cookie');
        if ($response->getStatusCode() < 300) {
            // A page of the preview hands the token on to its hypermedia clients; an error page never shows it.
            $response = PreviewUrls::inPage($response, $carried, $this->clients);
        }

        return self::withHeaders($response);
    }

    /**
     * The token $request carries: null for none, false for one that cannot be a token (a list, or
     * a header and a parameter that differ), else the token.
     */
    private static function carried(ServerRequestInterface $request): string|false|null
    {
        $header = $request->hasHeader(PreviewToken::HEADER) ? $request->getHeaderLine(PreviewToken::HEADER) : null;
        $query = $request->getQueryParams();
        if (!array_key_exists(PreviewToken::PARAM, $query)) {
            parse_str($request->getUri()->getQuery(), $query);
        }
        $param = $query[PreviewToken::PARAM] ?? null;
        if ($header === null && $param === null) {
            return null;
        }
        if (($param !== null && !is_string($param)) || ($header !== null && $param !== null && !hash_equals($header, $param))) {
            return false;
        }

        return $header ?? $param;
    }

    /** $request without the token: no header, no parameter in its query params or its URI. */
    private static function withoutToken(ServerRequestInterface $request): ServerRequestInterface
    {
        $query = $request->getQueryParams();
        unset($query[PreviewToken::PARAM]);
        $uri = $request->getUri();
        $kept = [];
        foreach ($uri->getQuery() === '' ? [] : explode('&', $uri->getQuery()) as $pair) {
            $name = rawurldecode(str_replace('+', ' ', explode('=', $pair, 2)[0]));
            if ($name !== PreviewToken::PARAM && !str_starts_with($name, PreviewToken::PARAM . '[')) {
                $kept[] = $pair;
            }
        }

        return $request
            ->withoutHeader(PreviewToken::HEADER)
            ->withQueryParams($query)
            ->withUri($uri->withQuery(implode('&', $kept)), true);
    }

    /** '/--component' or a path under it (the locale prefix is already stripped here). */
    private static function isComponentPath(string $path): bool
    {
        return $path === '/--component' || str_starts_with($path, '/--component/');
    }

    private function report(string $reason): void
    {
        $message = "Preview token refused: {$reason}.";
        if ($this->log !== null) {
            ($this->log)($message);
        } else {
            error_log($message);
        }
    }

    /** The plain page a refused token gets: nothing of the site's, nothing rendered. */
    private static function refused(): ResponseInterface
    {
        return new Response(403, ['Content-Type' => 'text/html; charset=UTF-8'], <<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Preview not available</title></head>
            <body><p>This preview is no longer available here. Open the preview again from the editor.</p></body>
            </html>
            HTML);
    }

    private static function withHeaders(ResponseInterface $response): ResponseInterface
    {
        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
