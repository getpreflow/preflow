<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What core and the packages need to know about a hypermedia client library (htmx, Datastar…)
 * to treat its requests right: which requests it sends, what the response may depend on, how
 * to send it elsewhere and how to tell it the nonce of the scripts a response brings.
 *
 * Preflow\Htmx\HypermediaDriver extends it, so the app's driver (config/app.php 'hypermedia')
 * is one; a package with its own client (Folio's admin ships htmx) adds another to
 * HypermediaProtocols. Code that has to know asks HypermediaProtocols::for($request), never
 * a client's headers directly.
 */
interface HypermediaProtocol
{
    /**
     * The client sent the request and puts the response into the page (a fragment), rather than
     * the browser loading a whole page. True for a history restore too (isHistoryRestore()).
     */
    public function isFragmentRequest(ServerRequestInterface $request): bool;

    /**
     * The client sent the request, but asks for the whole page again (htmx: going back to a
     * page not in its history cache). Such a request is answered and cached as a full page.
     */
    public function isHistoryRestore(ServerRequestInterface $request): bool;

    /**
     * The id of the element the client swaps the response into (no '#'), or null when the
     * request names none.
     */
    public function fragmentTarget(ServerRequestInterface $request): ?string;

    /**
     * The request headers the client sends that change what the response is: the page cache
     * keys a stored response on their values (left out for a history restore), and a response
     * may name them in Vary and still be stored.
     *
     * @return list<string>
     */
    public function varyHeaders(): array;

    /**
     * $response told to make the browser load $url as a whole page, or reload the current page
     * when $url is null, instead of putting the response into the page (a sign-in redirect, an
     * expired session). Keeps the headers; the status may change where the client acts on a
     * success only (htmx keeps it). AuthMiddleware sends a response told in headers only as
     * its 401 error page, one with a body as it is.
     */
    public function navigate(ResponseInterface $response, ?string $url): ResponseInterface;

    /**
     * $response with the CSP nonce its own <script>/<style> carry, where the client needs to
     * know it (htmx: HX-Nonce, which the page's nonce guard checks); unchanged where it doesn't.
     */
    public function announceNonce(ResponseInterface $response, string $nonce): ResponseInterface;

    /**
     * The response headers announceNonce() writes: they belong to one response, so the page
     * cache never stores them and a 304 drops them.
     *
     * @return list<string>
     */
    public function nonceHeaders(): array;

    /**
     * The CSP nonce of the page the request came from, when the client sends it back (null
     * otherwise). Core then answers with that nonce, so the scripts and styles the response
     * puts into the page pass the page's Content-Security-Policy.
     *
     * The value is untrusted client input. Core takes it from a fragment request only (never a
     * plain request or a history restore: HypermediaProtocols::pageNonce()), only a base64 value
     * of 22+ characters, for that response only, and the page cache never stores that response.
     * A driver must not print the nonce into the page (a data-* attribute, a meta tag): the
     * browser hides a nonce attribute from CSS and the DOM for a reason. Read it client-side from
     * the nonce property of a script element (script.nonce) when the request is sent.
     */
    public function pageNonce(ServerRequestInterface $request): ?string;
}
