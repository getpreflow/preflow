<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

/**
 * Where a "next" or "return to" value may send the visitor after a sign-in or a form: a path on
 * this site under one of the allowed prefixes, never a URL. A prefix matches whole segments
 * (`/employer` covers `/employer` and `/employer/jobs`, not `/employerx`); a query string may
 * follow. Refused: another host or a scheme, a double slash, a backslash, a dot segment, a
 * control or non-ASCII character, also percent-encoded (decoded down to the fixed point).
 */
final class SafeReturnPath
{
    /**
     * $next unchanged when it is a safe path under one of $allowedPrefixes, else null.
     *
     * @param list<string> $allowedPrefixes paths such as '/employer' ('/' allows every path)
     * @throws \InvalidArgumentException for a prefix that is no path (does not start with "/")
     */
    public static function check(?string $next, array $allowedPrefixes): ?string
    {
        $bases = [];
        foreach ($allowedPrefixes as $prefix) {
            if (!is_string($prefix) || !str_starts_with($prefix, '/')) {
                throw new \InvalidArgumentException('SafeReturnPath: an allowed prefix is a path starting with "/" ("/" allows every path), not ' . var_export($prefix, true) . '.');
            }
            $bases[] = preg_quote(rtrim($prefix, '/'), '#');
        }
        if ($next === null || $next === '' || $next[0] !== '/' || $allowedPrefixes === []) {
            return null;
        }
        // Browsers decode %2e and friends before resolving dot segments: judge every decoded form, down to the fixed point.
        $seen = $next;
        for ($i = 0; $i < 5; $i++) {
            if (str_contains($seen, '..') || str_contains($seen, '//') || str_contains($seen, '\\') || preg_match('/[^\x21-\x7E]/', $seen) === 1) {
                return null;
            }
            $decoded = rawurldecode($seen);
            if ($decoded === $seen) {
                break;
            }
            $seen = $decoded;
        }
        if ($seen !== rawurldecode($seen)) {
            return null;
        }

        return preg_match('#^(?:' . implode('|', array_unique($bases)) . ')(/[A-Za-z0-9._~%/-]*)?(\?[\x21-\x7E]*)?$#D', $next) === 1 ? $next : null;
    }
}
