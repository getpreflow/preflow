<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

/**
 * A hypermedia client (HypermediaProtocol) that can be told, in a page's HTML, to send request
 * headers with every request it makes from that page (htmx: hx-headers on <body>). Core uses it to
 * hand a preview token on to a previewed page's follow-up requests (PreviewUrls::inPage()).
 *
 * Optional: a client without it still works; its requests then carry only what the URLs carry
 * (component URLs carry the token as a parameter). HtmxDriver implements it.
 *
 * @since 0.81.0
 */
interface CarriesRequestHeaders
{
    /**
     * $html with the client told to send $headers (name => value) with every request it makes
     * from the page, values escaped for where they go. Unchanged when the page has no place for
     * it (no <body>), and never changes anything else in the page. Headers the page already told
     * the client to send keep their own values unless $headers names them.
     *
     * @param array<string, string> $headers
     */
    public function carryHeaders(string $html, array $headers): string;
}
