<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

/**
 * Where a "next" or "return to" value may send the visitor after a sign-in or a form: a path on
 * this site under one of the allowed prefixes, never a URL. A prefix matches whole segments
 * (`/employer` covers `/employer` and `/employer/jobs`, not `/employerx`); a query string may
 * follow. Path segments may carry RFC 3986 sub-delims (`+ , : @ ( ) ; = ! $ ' *`) and
 * percent-encoded UTF-8 ('/de/%C3%BCber-uns'). Refused: another host or a scheme, a double
 * slash, a backslash, a `..` segment (a lone `.` is not refused), a control or space character,
 * raw non-ASCII bytes, invalid UTF-8, a path starting '/@', each also percent-encoded (decoded
 * down to the fixed point).
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
            // The raw value is plain ASCII; a decoded form may hold UTF-8 but no control or space byte.
            $bad = $i === 0 ? '/[^\x21-\x7E]/' : '/[\x00-\x20\x7F]/';
            if (str_contains($seen, '..') || str_contains($seen, '//') || str_contains($seen, '\\')
                || preg_match($bad, $seen) === 1 || preg_match('//u', $seen) !== 1
            ) {
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

        return preg_match('#^(?:' . implode('|', array_unique($bases)) . ')(?!/@)(/[A-Za-z0-9._~%/!$&\'()*+,;=:@-]*)?(\?[\x21-\x7E]*)?$#D', $next) === 1 ? $next : null;
    }
}
