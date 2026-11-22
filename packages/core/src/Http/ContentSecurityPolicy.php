<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Content-Security-Policy header values, parsed (CSP Level 3, "parse a serialized CSP list"):
 * each value is a comma-separated list of policies, each policy a semicolon-separated list of
 * directives. Directive names are case-insensitive and only the first occurrence of a name in a
 * policy counts; empty policies (a trailing comma) and empty directives are left out. Reads a
 * policy someone else made (a page rendered in a frame of its own brings its own); it does not
 * build one (SecurityHeadersMiddleware does).
 *
 * @since 0.82.0
 */
final class ContentSecurityPolicy
{
    /** The directives that govern an element of each kind, the most specific first. */
    private const array FALLBACKS = [
        'script' => ['script-src-elem', 'script-src', 'default-src'],
        'style' => ['style-src-elem', 'style-src', 'default-src'],
    ];

    /** @param list<array<string, list<string>>> $policies */
    private function __construct(private readonly array $policies) {}

    /** The policies in one or more header values, in order. */
    public static function parse(string ...$values): self
    {
        $policies = [];
        foreach ($values as $value) {
            foreach (explode(',', $value) as $serialized) {
                $policy = [];
                foreach (explode(';', $serialized) as $directive) {
                    $tokens = preg_split('/\s+/', trim($directive), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    if ($tokens === []) {
                        continue;
                    }
                    $policy[strtolower(array_shift($tokens))] ??= $tokens;
                }
                if ($policy !== []) {
                    $policies[] = $policy;
                }
            }
        }

        return new self($policies);
    }

    /**
     * The policy a response is sent with: its enforced Content-Security-Policy when it has one,
     * else its Content-Security-Policy-Report-Only trial; null when it has neither.
     */
    public static function ofResponse(ResponseInterface $response): ?self
    {
        foreach (['Content-Security-Policy', 'Content-Security-Policy-Report-Only'] as $header) {
            if ($response->hasHeader($header)) {
                return self::parse(...$response->getHeader($header));
            }
        }

        return null;
    }

    /** @return list<array<string, list<string>>> each policy: directive name (lower case) => its source expressions */
    public function policies(): array
    {
        return $this->policies;
    }

    /**
     * The nonce an inline element of $kind ('script' or 'style') may carry. In each policy the
     * directive that governs it is $kind-src-elem, else $kind-src, else default-src; a policy
     * with none of them does not restrict the element. A browser enforces every policy of the
     * list, so the nonce must be one each restricting policy allows: null when a governing
     * directive names no nonce (even when default-src names one: it does not apply then), when
     * two policies name different nonces, or when no policy names one.
     *
     * @throws \InvalidArgumentException for another kind
     */
    public function nonceFor(string $kind): ?string
    {
        $order = self::FALLBACKS[$kind] ?? throw new \InvalidArgumentException("A nonce is for 'script' or 'style', got '{$kind}'.");
        $nonce = null;
        foreach ($this->policies as $policy) {
            foreach ($order as $name) {
                if (!isset($policy[$name])) {
                    continue;
                }
                $own = null;
                foreach ($policy[$name] as $source) {
                    if (preg_match("/^'nonce-([^']+)'$/i", $source, $m) === 1) {
                        $own = $m[1];
                        break;
                    }
                }
                if ($own === null || ($nonce !== null && $own !== $nonce)) {
                    return null;
                }
                $nonce = $own;
                break;
            }
        }

        return $nonce;
    }
}
