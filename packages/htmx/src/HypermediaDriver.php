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

    /** Ask the client to fire this event when the response arrives (recorded, sent by applyEffects()). */
    public function triggerEvent(string $event): void;

    /** Ask the client to go to this URL (recorded, sent by applyEffects()). */
    public function redirect(string $url): void;

    /** Ask the client to put this URL in the address bar and history (recorded, sent by applyEffects()). */
    public function pushUrl(string $url): void;

    /**
     * The response with the effects recorded so far (triggerEvent(), redirect(), pushUrl()) in
     * the form the client reads them: headers, or events in the body. The component endpoint
     * calls it on every response it sends.
     */
    public function applyEffects(ResponseInterface $response): ResponseInterface;

    public function assetTag(): string;
}
