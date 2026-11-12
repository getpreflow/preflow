<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/** Text helpers for composed mails: header-safe values, the site's name, the HTML paragraphs, absolute links. */
final class MailText
{
    /** Control characters (CR/LF included) collapse to one space: a value must not break a header line. */
    public static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value));
    }

    /**
     * What a mail calls the site: app.name ($appName), else the host of app.url ($appUrl), else
     * null (a mail then words itself without the site's name). One line.
     */
    public static function siteName(mixed $appName, mixed $appUrl): ?string
    {
        $name = is_string($appName) ? self::oneLine($appName) : '';
        if ($name !== '') {
            return $name;
        }
        $host = is_string($appUrl) ? parse_url(trim($appUrl), PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Safe HTML for a plain text: one entry per paragraph (split at blank lines), escaped, http(s)
     * URLs linked in the accent colour, single line breaks kept.
     *
     * @param list<string>|null $links only these URLs (exact text) are linked, the others stay plain; null links every one
     * @return list<string>
     */
    public static function paragraphs(string $text, string $accent, ?array $links = null): array
    {
        $style = 'color:' . self::escape($accent);
        $allowed = $links === null ? null : array_map(self::escape(...), $links);
        $out = [];
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $text))) ?: [] as $p) {
            $html = (string) preg_replace_callback(
                '~https?://[^\s<]+~',
                static fn (array $m): string => $allowed === null || in_array($m[0], $allowed, true)
                    ? '<a href="' . $m[0] . '" style="' . $style . '">' . $m[0] . '</a>'
                    : $m[0],
                self::escape($p),
            );
            $out[] = nl2br($html, false);
        }

        return $out;
    }

    /**
     * A link for a mail: a path ("/invite/x") made absolute with $appUrl, or an absolute http(s) URL
     * with a host as is.
     *
     * @throws \InvalidArgumentException for anything else (protocol-relative, other schemes, whitespace or control characters)
     */
    public static function absoluteUrl(string $pathOrUrl, string $appUrl): string
    {
        if (preg_match('/[\p{Cc}\p{Cf}\s]/u', $pathOrUrl) !== 1) {
            if (str_starts_with($pathOrUrl, '/') && !str_starts_with($pathOrUrl, '//') && !str_contains($pathOrUrl, '\\')) {
                return rtrim($appUrl, '/') . $pathOrUrl;
            }
            $parts = parse_url($pathOrUrl);
            if (is_array($parts) && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && ($parts['host'] ?? '') !== '') {
                return $pathOrUrl;
            }
        }

        throw new \InvalidArgumentException('The link must be a path or an absolute http(s) URL.');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
