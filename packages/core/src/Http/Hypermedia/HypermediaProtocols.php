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

    /** Adds $protocol; one of a class already added is ignored (the first one stays). */
    public function add(HypermediaProtocol $protocol): void
    {
        foreach ($this->protocols as $known) {
            if ($known::class === $protocol::class) {
                return;
            }
        }
        $this->protocols[] = $protocol;
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

    /** @return list<string> every client's varyHeaders(), each name once (case-insensitive) */
    public function varyHeaders(): array
    {
        return self::unique(array_merge(...array_map(static fn (HypermediaProtocol $p): array => $p->varyHeaders(), $this->protocols)));
    }

    /** @return list<string> every client's nonceHeaders(), each name once (case-insensitive) */
    public function nonceHeaders(): array
    {
        return self::unique(array_merge(...array_map(static fn (HypermediaProtocol $p): array => $p->nonceHeaders(), $this->protocols)));
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
