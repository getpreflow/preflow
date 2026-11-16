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
 * raw non-ASCII bytes, invalid UTF-8, a path starting '/@' (right after the prefix), each also
 * percent-encoded: every decoded form is judged down to the fixed point, and a value that has not
 * settled after five decodes is refused.
 */
final class SafeReturnPath
{
    /** How many times a value may be percent-decoded before it must have settled. */
    private const int MAX_DECODES = 5;

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
        // Browsers decode %2e and friends before resolving dot segments: judge every decoded form,
        // the final one included, and refuse a value still changing after MAX_DECODES decodes.
        $start = '#^(?:' . implode('|', array_unique($bases)) . ')/@#';
        $seen = $next;
        for ($decodes = 0; ; $decodes++) {
            if (self::refused($seen, $decodes === 0, $start)) {
                return null;
            }
            $decoded = rawurldecode($seen);
            if ($decoded === $seen) {
                break;
            }
            if ($decodes === self::MAX_DECODES) {
                return null;
            }
            $seen = $decoded;
        }

        return preg_match('#^(?:' . implode('|', array_unique($bases)) . ')(?!/@)(/[A-Za-z0-9._~%/!$&\'()*+,;=:@-]*)?(\?[\x21-\x7E]*)?$#D', $next) === 1 ? $next : null;
    }

    /** Whether one form of the value (the raw one, or a decoded one) is refused. */
    private static function refused(string $form, bool $raw, string $start): bool
    {
        // The raw value is plain ASCII; a decoded form may hold UTF-8 but no control or space byte.
        $bad = $raw ? '/[^\x21-\x7E]/' : '/[\x00-\x20\x7F]/';

        return str_contains($form, '..') || str_contains($form, '//') || str_contains($form, '\\')
            || preg_match($bad, $form) === 1 || preg_match('//u', $form) !== 1
            || preg_match($start, $form) === 1;
    }
}
