<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Preflow\Core\Http\Hypermedia\HypermediaProtocol;
use Psr\Http\Message\ResponseInterface;

/**
 * The client library a Preflow app talks to (htmx by default): the attributes the hd.* helpers
 * write, how a request from the library is recognised, and the effects an action asks for in
 * its response. One per app: config/app.php 'hypermedia' names its class (default HtmxDriver),
 * the container builds it, and the hd.* helpers, the component endpoint and the form helpers
 * (when it also implements Preflow\View\FormHypermediaDriver) use it.
 *
 * Its requests and responses as core sees them (fragment requests, history restores, the
 * target, the page cache's vary headers, navigating, nonces) are the HypermediaProtocol
 * methods it extends: core finds the app's driver in HypermediaProtocols, so auth, CSRF, the
 * security headers, the page cache and GEO treat its requests as it says.
 *
 * Since 0.81 what a driver records for a response (triggerEvent(), redirect(), pushUrl()) and
 * what applyEffects() adds from the render (its nonce) belong to the render frame they were
 * recorded in: keep them in a frame slot (Contexts::frame()->slot(), as ResponseHeaders does),
 * so an effect recorded in one render (the SEO audit, a preview, a request the page asks for)
 * never reaches another render's response, and Contexts::within() keeps the frame's. The
 * headers they are sent in are the driver's: implement Preflow\Core\Http\Hypermedia\OwnsResponseHeaders
 * (optional; without it pages may set your effect headers as response hints). See the test
 * fixture SignalDriver/SignalEffects for a frame slot. HypermediaDriverContract checks both.
 */
interface HypermediaDriver extends HypermediaProtocol
{
    public function actionAttrs(
        string $method,
        string $url,
        string $targetId,
        SwapStrategy $swap,
        array $extra = [],
    ): HtmlAttributes;

    public function listenAttrs(
        string $event,
        string $url,
        string $targetId,
    ): HtmlAttributes;

    /** Ask the client to fire this event when the response arrives (recorded in the current render frame, sent by applyEffects()). */
    public function triggerEvent(string $event): void;

    /** Ask the client to go to this URL (recorded, sent by applyEffects()). */
    public function redirect(string $url): void;

    /** Ask the client to put this URL in the address bar and history (recorded, sent by applyEffects()). */
    public function pushUrl(string $url): void;

    /**
     * The response with the effects the current render frame recorded so far (triggerEvent(),
     * redirect(), pushUrl()) in the form the client reads them: headers, or events in the body.
     * The component endpoint calls it on every response it sends.
     */
    public function applyEffects(ResponseInterface $response): ResponseInterface;

    public function assetTag(): string;
}
