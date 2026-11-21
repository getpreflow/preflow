<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\Contexts;
use Psr\Http\Message\ResponseInterface;

/**
 * Response headers a page or component adds while it renders, for example
 * `Cache-Control: no-store` on a form's outcome page. The kernel puts them on the response the
 * route gives (and on its error page), inside the middleware pipeline: the page cache treats a
 * `no-store` (or `private`) from here like any such response and never stores the page.
 *
 * Each render frame has its own (a frame slot): the one the container gives, ofCurrentFrame(),
 * works on the current frame's at each call, so a header a render adds never reaches another
 * render's response. A component reaches it through the container; a template through
 * `response_header(name, value)`. Hints made with `new` are a plain list of their own.
 *
 * Headers core or a hypermedia client owns are refused (RESERVED, the X-Page-Cache family, and
 * what the app adds: the headers its clients own and their nonce headers, OwnsResponseHeaders::
 * ownedHeaders() and HypermediaProtocol::nonceHeaders(), htmx's HX-* family among them; a client
 * without OwnsResponseHeaders gets only its nonce headers reserved): a cookie goes through the
 * CookieQueue, a redirect is a response of its own, the content's type and length come from the
 * route, the CSP carries the render's nonce, the client's headers come from its driver's calls.
 *
 * The error page gets only the hints that make it less cacheable (restrictive(): no-store,
 * no-cache, private, must-revalidate, proxy-revalidate of Cache-Control), never one that makes a
 * failure cacheable. Live parts render after the route answered: what they add goes on the
 * response the visitor is served, only the restrictive ones (a per-visitor part never widens
 * caching), never on the stored page.
 *
 * @since 0.81.0
 */
final class ResponseHeaderHints
{
    /** Header names (lower case) a page or component may not set: core or the route owns them. */
    public const array RESERVED = [
        'set-cookie', 'content-security-policy', 'content-security-policy-report-only', 'content-length',
        'content-type', 'transfer-encoding', 'location',
    ];

    /** Cache-Control directives that only make a response less cacheable. */
    private const array RESTRICTIVE = ['no-store', 'no-cache', 'private', 'must-revalidate', 'proxy-revalidate'];

    /** @var array<string, array{string, string}> lower-case name => [name as given, value] */
    private array $headers = [];

    private bool $frameBound = false;

    /** @var (\Closure(): list<string>)|null more reserved names, asked when a header is set */
    private ?\Closure $reserved = null;

    /**
     * The hints that follow the render frames: each call works on the current frame's own.
     *
     * @param (\Closure(): list<string>)|null $reserved since 0.81.0: more header names nobody may set
     *        (the app passes the headers its hypermedia clients own and their nonce headers); a
     *        name ending in '*' stands for every header that starts with the rest
     */
    public static function ofCurrentFrame(?\Closure $reserved = null): self
    {
        $hints = new self();
        $hints->frameBound = true;
        $hints->reserved = $reserved;

        return $hints;
    }

    /**
     * The response carries $name: $value, in place of a value the route gave it (a later call
     * for the same name wins).
     *
     * @throws \InvalidArgumentException when the name or the value could split the header, or
     *                                   core owns the header (see the class docblock)
     */
    public function set(string $name, string $value): void
    {
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
            throw new \InvalidArgumentException("Not a response header: {$name}.");
        }
        $lower = strtolower($name);
        if (in_array($lower, self::RESERVED, true) || str_starts_with($lower, 'x-page-cache')
            || \Preflow\Core\Http\Hypermedia\HypermediaProtocols::matches($lower, $this->reserved?->__invoke() ?? [])) {
            throw new \InvalidArgumentException("The {$name} header is set by the framework, not by a page or component.");
        }
        $this->target()->headers[$lower] = [$name, $value];
    }

    /** The value added for $name (any case), null when none was. */
    public function get(string $name): ?string
    {
        return $this->target()->headers[strtolower($name)][1] ?? null;
    }

    /** @return array<string, string> name => value, in the order they were first added */
    public function all(): array
    {
        $all = [];
        foreach ($this->target()->headers as [$name, $value]) {
            $all[$name] = $value;
        }

        return $all;
    }

    /** $response with every added header (unchanged when none was added). */
    public function applyTo(ResponseInterface $response): ResponseInterface
    {
        foreach ($this->target()->headers as [$name, $value]) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * $response with only the hints that make it less cacheable: the restrictive directives of an
     * added Cache-Control (no-store, no-cache, private, must-revalidate, proxy-revalidate), in
     * place of the response's own. Unchanged when none was added. For the error page and a page
     * served from the cache.
     */
    public function applyRestrictiveTo(ResponseInterface $response): ResponseInterface
    {
        $control = $this->target()->headers['cache-control'][1] ?? null;
        if ($control === null) {
            return $response;
        }
        $directives = array_values(array_filter(
            array_map(static fn (string $d): string => strtolower(trim($d)), explode(',', $control)),
            static fn (string $d): bool => in_array($d, self::RESTRICTIVE, true),
        ));

        return $directives === [] ? $response : $response->withHeader('Cache-Control', implode(', ', $directives));
    }

    /** The hints the calls work on: the current frame's for frame-bound hints, else these. */
    private function target(): self
    {
        if (!$this->frameBound) {
            return $this;
        }

        return Contexts::slot(self::class, static fn (): self => new self()) ?? $this;
    }
}
