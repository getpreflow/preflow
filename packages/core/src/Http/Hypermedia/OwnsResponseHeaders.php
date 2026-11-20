<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

/**
 * A hypermedia client (HypermediaProtocol) that owns response headers: the ones it reads to act
 * on a response (htmx: HX-Trigger, HX-Redirect, HX-Push-Url…) and the ones its navigate(),
 * announceNonce() and, for a driver, applyEffects() write. A page or component may then not set
 * them as response hints (ResponseHeaderHints refuses them); the client's own calls do.
 *
 * Optional: a client without it still works, but core then reserves only its nonceHeaders() for
 * it, so pages may set its effect headers. HtmxDriver implements it.
 *
 * @since 0.81.0
 */
interface OwnsResponseHeaders
{
    /**
     * The response headers that belong to the client. A name ending in '*' stands for every
     * header that starts with the rest ('HX-*'). Case does not matter.
     *
     * @return list<string>
     */
    public function ownedHeaders(): array;
}
