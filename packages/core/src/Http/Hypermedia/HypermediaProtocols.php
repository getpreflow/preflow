<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The hypermedia clients an app talks to: the app's driver first (config/app.php 'hypermedia',
 * htmx by default), then those packages add for their own screens (Folio's htmx). Bound in the
 * container from the start of the boot, filled while it runs; read per request. Every request
 * Application::handle() passes on carries it too, as the attribute HypermediaProtocols::class.
 */
final class HypermediaProtocols
{
    /** @var list<HypermediaProtocol> */
    private array $protocols = [];

    /**
     * Adds $protocol; one of a class already added is ignored (the first one stays). A StandIn
     * counts as the class it stands in for.
     */
    public function add(HypermediaProtocol $protocol): void
    {
        foreach ($this->protocols as $known) {
            if (self::identity($known) === self::identity($protocol)) {
                return;
            }
        }
        $this->protocols[] = $protocol;
    }

    private static function identity(HypermediaProtocol $protocol): string
    {
        return $protocol instanceof StandIn ? $protocol->standsInFor() : $protocol::class;
    }

    /** @return list<HypermediaProtocol> */
    public function all(): array
    {
        return $this->protocols;
    }

    /** The client that sent $request (a fragment request or a history restore), or null for a plain browser request. */
    public function for(ServerRequestInterface $request): ?HypermediaProtocol
    {
        foreach ($this->protocols as $protocol) {
            if ($protocol->isFragmentRequest($request) || $protocol->isHistoryRestore($request)) {
                return $protocol;
            }
        }

        return null;
    }

    /** A client sent $request to put the response into the page (history restores included). */
    public function isFragmentRequest(ServerRequestInterface $request): bool
    {
        return $this->for($request)?->isFragmentRequest($request) ?? false;
    }

    /** A client sent $request for a whole page (a history restore). */
    public function isHistoryRestore(ServerRequestInterface $request): bool
    {
        return $this->for($request)?->isHistoryRestore($request) ?? false;
    }

    /** A fragment request, and not a history restore: the response goes into the page. */
    public function wantsFragment(ServerRequestInterface $request): bool
    {
        $client = $this->for($request);

        return $client !== null && $client->isFragmentRequest($request) && !$client->isHistoryRestore($request);
    }

    /**
     * $response told to load $url (null: reload) by the client that sent $request; null when no
     * client sent it (answer a plain browser request with a redirect).
     */
    public function navigate(ServerRequestInterface $request, ResponseInterface $response, ?string $url): ?ResponseInterface
    {
        $client = $this->for($request);

        return $client !== null && $client->isFragmentRequest($request) ? $client->navigate($response, $url) : null;
    }

    /**
     * The page nonce a fragment request sent back (HypermediaProtocol::pageNonce()), or null:
     * never from a plain request or a history restore (those are whole pages). Untrusted input;
     * core adopts it for that response only (NonceGenerator::adoptInFrame()) and never stores it.
     */
    public function pageNonce(ServerRequestInterface $request): ?string
    {
        return $this->wantsFragment($request) ? $this->for($request)?->pageNonce($request) : null;
    }

    /** @return list<string> every client's varyHeaders(), each name once (case-insensitive) */
    public function varyHeaders(): array
    {
        return self::unique(array_merge(...array_map(static fn (HypermediaProtocol $p): array => $p->varyHeaders(), $this->protocols)));
    }

    /**
     * $response told to vary on the request headers of the client that sent $request
     * (HypermediaProtocol::varyHeaders(); htmx: HX-Request…), when $request is a fragment request:
     * a fragment answers on the same URL as its page, so a cache (the browser's back/forward
     * cache included) must not show it for the page. Names already in Vary stay, each once; any
     * other response is returned as it is.
     *
     * @since 0.84.0
     */
    public function varying(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->wantsFragment($request)) {
            return $response;
        }
        $names = [];
        foreach (explode(',', $response->getHeaderLine('Vary')) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $names[strtolower($name)] = $name;
            }
        }
        if (isset($names['*'])) {
            return $response;
        }
        foreach ($this->for($request)?->varyHeaders() ?? [] as $name) {
            $names[strtolower($name)] ??= $name;
        }

        return $response->withHeader('Vary', implode(', ', $names));
    }

    /** @return list<string> every client's nonceHeaders(), each name once (case-insensitive) */
    public function nonceHeaders(): array
    {
        return self::unique(array_merge(...array_map(static fn (HypermediaProtocol $p): array => $p->nonceHeaders(), $this->protocols)));
    }

    /**
     * The headers of every client that owns some (OwnsResponseHeaders::ownedHeaders()), each name
     * once (case-insensitive); a client without that interface owns none here.
     *
     * @since 0.81.0
     * @return list<string>
     */
    public function ownedHeaders(): array
    {
        $owned = [];
        foreach ($this->protocols as $protocol) {
            if ($protocol instanceof OwnsResponseHeaders) {
                array_push($owned, ...$protocol->ownedHeaders());
            }
        }

        return self::unique($owned);
    }

    /**
     * $html with every client that can (CarriesRequestHeaders) told to send $headers with each
     * request it makes from the page; unchanged when none can.
     *
     * @since 0.81.0
     * @param array<string, string> $headers
     */
    public function carryHeaders(string $html, array $headers): string
    {
        foreach ($this->protocols as $protocol) {
            if ($protocol instanceof CarriesRequestHeaders) {
                $html = $protocol->carryHeaders($html, $headers);
            }
        }

        return $html;
    }

    /**
     * Whether $name (any case) is one of $names, where a name ending in '*' stands for every
     * header that starts with the rest.
     *
     * @since 0.81.0
     * @param list<string> $names
     */
    public static function matches(string $name, array $names): bool
    {
        $lower = strtolower($name);
        foreach ($names as $owned) {
            $owned = strtolower($owned);
            if (str_ends_with($owned, '*') ? str_starts_with($lower, substr($owned, 0, -1)) : $lower === $owned) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function unique(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[strtolower($name)] ??= $name;
        }

        return array_values($out);
    }
}
