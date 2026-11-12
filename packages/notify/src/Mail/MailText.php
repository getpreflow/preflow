<?php

declare(strict_types=1);

namespace Preflow\Notify\Mail;

/** Text helpers for composed mails: header-safe values, the HTML paragraphs, absolute links. */
final class MailText
{
    /** Control characters (CR/LF included) collapse to one space: a value must not break a header line. */
    public static function oneLine(string $value): string
    {
        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $value));
    }

    /**
     * Safe HTML for a plain text: one entry per paragraph (split at blank lines), escaped, http(s)
     * URLs linked in the accent colour, single line breaks kept.
     *
     * @return list<string>
     */
    public static function paragraphs(string $text, string $accent): array
    {
        $style = 'color:' . self::escape($accent);
        $out = [];
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r\n", "\n", $text))) ?: [] as $p) {
            $html = self::escape($p);
            $html = (string) preg_replace('~https?://[^\s<]+~', '<a href="$0" style="' . $style . '">$0</a>', $html);
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
