<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Http\Hypermedia\HypermediaProtocols;
use Psr\Http\Message\ResponseInterface;

/**
 * Hands a preview token on to the follow-up requests of a render made under it (a render whose
 * context has a PreviewGrant: the whole-page preview, a request admitted by
 * PreviewTokenMiddleware, a request asked for inside either). Two carriers (ruling R5):
 * - carry(): component endpoint URLs (actions, renders, listeners) get the `_preview` parameter;
 * - inPage(): a page's hypermedia clients send the X-Preflow-Preview header on every request they
 *   make from it (htmx: hx-headers on <body>), which covers boosted forms and a site's own calls.
 * Plain links and plain forms carry nothing: they leave the preview for the live site.
 *
 * Outside a granted render both change nothing, so no other response ever holds a token.
 *
 * @since 0.81.0
 */
final class PreviewUrls
{
    /** $url with `_preview=<token>` added when the current render has a PreviewGrant with a token; else $url unchanged. */
    public static function carry(string $url): string
    {
        $token = Contexts::peek()?->get(PreviewGrant::class)?->token();
        if ($token === null || $token === '') {
            return $url;
        }
        $hash = '';
        if (($at = strpos($url, '#')) !== false) {
            $hash = substr($url, $at);
            $url = substr($url, 0, $at);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . PreviewToken::PARAM . '=' . rawurlencode($token) . $hash;
    }

    /**
     * $response with `Vary: X-Preflow-Preview` added (unless it names it, or `*`, already): a cache
     * that keeps it never answers a preview's request, which carries its token in that header, with
     * it. A no-store response is left as it is (no cache keeps it); a private one is not, as the
     * browser's own cache may keep it.
     */
    public static function withVary(ResponseInterface $response): ResponseInterface
    {
        if (preg_match('/(^|[\s,])no-store([\s,=]|$)/i', $response->getHeaderLine('Cache-Control')) === 1) {
            return $response;
        }
        foreach (explode(',', strtolower($response->getHeaderLine('Vary'))) as $name) {
            if (in_array(trim($name), [strtolower(PreviewToken::HEADER), '*'], true)) {
                return $response;
            }
        }

        return $response->withAddedHeader('Vary', PreviewToken::HEADER);
    }

    /**
     * $response with $token handed to the page's hypermedia clients (CarriesRequestHeaders):
     * every request they make from the page sends it as the X-Preflow-Preview header. Only an
     * HTML response with a <body> changes; its Content-Length goes, as the body grew.
     */
    public static function inPage(ResponseInterface $response, #[\SensitiveParameter] string $token, ?HypermediaProtocols $clients): ResponseInterface
    {
        if ($token === '' || $clients === null || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html')) {
            return $response;
        }
        $html = (string) $response->getBody();
        $carried = $clients->carryHeaders($html, [PreviewToken::HEADER => $token]);
        if ($carried === $html) {
            return $response;
        }

        return $response
            ->withoutHeader('Content-Length')
            ->withBody((new \Nyholm\Psr7\Factory\Psr17Factory())->createStream($carried));
    }
}
