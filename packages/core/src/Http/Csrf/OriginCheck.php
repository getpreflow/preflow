<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Csrf;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Layer 1 of CSRF protection: decides from Sec-Fetch-Site, Origin and Referer
 * whether an unsafe request was sent by a page of this site.
 */
final class OriginCheck
{
    /** @var list<string> */
    private readonly array $trusted;

    private readonly ?string $appOrigin;

    /** @param list<string> $trustedOrigins */
    public function __construct(?string $appUrl, array $trustedOrigins = [])
    {
        $this->appOrigin = $appUrl !== null ? self::normalize($appUrl) : null;
        $this->trusted = array_values(array_filter(array_map(
            static fn (mixed $origin): ?string => is_string($origin) ? self::normalize($origin) : null,
            $trustedOrigins,
        )));
    }

    public function verdict(ServerRequestInterface $request): OriginVerdict
    {
        $origin = self::normalize($request->getHeaderLine('Origin'));

        if ($request->hasHeader('Sec-Fetch-Site')) {
            if (strtolower(trim($request->getHeaderLine('Sec-Fetch-Site'))) === 'same-origin') {
                return OriginVerdict::Pass;
            }

            return $origin !== null && in_array($origin, $this->trusted, true)
                ? OriginVerdict::Pass
                : OriginVerdict::Refuse;
        }

        $source = $origin ?? self::normalize($request->getHeaderLine('Referer'));
        if ($source === null) {
            return OriginVerdict::Unknown;
        }

        $own = self::normalize((string) $request->getUri()->withPath('')->withQuery('')->withFragment(''));

        return $source === $this->appOrigin || $source === $own || in_array($source, $this->trusted, true)
            ? OriginVerdict::Pass
            : OriginVerdict::Refuse;
    }

    /** Reduces a URL to scheme://host[:port] (lowercase, default port dropped); null when it has none. */
    private static function normalize(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        if ($port === ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null))) {
            $port = null;
        }

        return $scheme . '://' . strtolower($parts['host']) . ($port !== null ? ':' . $port : '');
    }
}
