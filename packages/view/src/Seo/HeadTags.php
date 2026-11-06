<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * Adds robots, theme-color, Open Graph, Twitter card and JSON-LD tags to a
 * page's <head>. A tag the page already has (same `name`/`property` inside
 * <head>; JSON-LD anywhere in the page) is left alone, so layouts and
 * components can always override.
 */
final class HeadTags
{
    /**
     * @param array<string, string> $themeColor SeoConfig::$themeColor
     * @param array<array>          $graph      JSON-LD nodes; none prints no script
     * @param bool                  $cards      false: robots and theme-color only (a private page: no previews, no structured data)
     */
    public static function inject(string $html, PageMeta $meta, string $siteName, array $themeColor, array $graph, bool $cards = true): string
    {
        $close = stripos($html, '</head>');
        if ($close === false) {
            return $html;
        }

        $open = preg_match('~<head(?:\s[^>]*)?>~i', $html, $match, PREG_OFFSET_CAPTURE) === 1 && $match[0][1] < $close
            ? $match[0][1]
            : 0;
        $head = substr($html, $open, $close - $open);
        $existing = self::existingNames($head);
        $missing = static fn (string $key): bool => !isset($existing[$key]);

        $tags = [];
        if ($missing('robots')) {
            $tags[] = self::meta('name', 'robots', $meta->noindex ? 'noindex' : 'max-image-preview:large');
        }
        if ($missing('theme-color')) {
            foreach ($themeColor as $scheme => $color) {
                $tags[] = self::meta('name', 'theme-color', $color, $scheme === '' ? null : "(prefers-color-scheme: {$scheme})");
            }
        }

        // A page kept out of search, or a private one, gets no preview cards or structured data.
        if (!$meta->noindex && $cards) {
            $og = [
                'og:site_name' => $siteName,
                'og:title' => $meta->title,
                'og:description' => self::description($meta->description),
                'og:type' => $meta->ogType,
                'og:url' => $meta->url,
                'og:locale' => self::ogLocale($meta->locale),
                'og:updated_time' => trim($meta->modified),
            ];
            foreach ($og as $property => $content) {
                if ($content !== '' && $missing($property)) {
                    $tags[] = self::meta('property', $property, $content);
                }
            }
            if ($meta->locale !== '' && $missing('og:locale:alternate')) {
                $alternates = array_unique(array_map(self::ogLocale(...), $meta->alternateLocales));
                foreach ($alternates as $locale) {
                    if ($locale !== '' && $locale !== self::ogLocale($meta->locale)) {
                        $tags[] = self::meta('property', 'og:locale:alternate', $locale);
                    }
                }
            }
            // The image tags describe one image: if the page names its own, add none of ours.
            $image = $meta->image;
            if ($image !== null && ($image['url'] ?? '') !== '' && $missing('og:image')) {
                $tags[] = self::meta('property', 'og:image', $image['url']);
                foreach (['width', 'height'] as $dimension) {
                    if ((int) ($image[$dimension] ?? 0) > 0) {
                        $tags[] = self::meta('property', "og:image:{$dimension}", (string) (int) $image[$dimension]);
                    }
                }
                if (($image['alt'] ?? '') !== '') {
                    $tags[] = self::meta('property', 'og:image:alt', $image['alt']);
                }
            }
            if ($missing('twitter:card')) {
                $hasImage = isset($existing['og:image']) || ($image['url'] ?? '') !== '';
                $tags[] = self::meta('name', 'twitter:card', $hasImage ? 'summary_large_image' : 'summary');
            }
            // Data, never executed, so it needs no CSP nonce. Structured data a component printed
            // in the body counts as the page's own too.
            if ($graph !== [] && preg_match('~<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json~i', $html) !== 1) {
                $tags[] = '<script type="application/ld+json">' . JsonLd::encode($graph) . '</script>';
            }
        }

        if ($tags === []) {
            return $html;
        }

        return substr_replace($html, implode("\n", $tags) . "\n", $close, 0);
    }

    /** Plain text for a description: no tags, collapsed whitespace, cut at a word to at most $max characters. */
    public static function description(string $text, int $max = 200): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('~[\s\x{00A0}]+~u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        // A cut that ends right before a space already ends on a whole word; otherwise go back
        // to the last space so no word is cut in half.
        $space = mb_substr($text, $max - 1, 1) === ' ' ? false : mb_strrpos($cut, ' ');
        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        // Not rtrim(): its byte list would chop the tails of multibyte characters.
        return (preg_replace('~[\s,;:.\x{2013}\x{2014}-]+$~u', '', $cut) ?? $cut) . '…';
    }

    /**
     * Lower-cased `name` and `property` values of the <meta> tags in $head.
     *
     * @return array<string, true>
     */
    private static function existingNames(string $head): array
    {
        $names = [];
        preg_match_all('~<meta\b[^>]*>~i', $head, $metas);
        foreach ($metas[0] as $tag) {
            preg_match_all('~(?<![\w-])(?:name|property)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $tag, $attributes, PREG_SET_ORDER);
            foreach ($attributes as $attribute) {
                $names[strtolower(trim($attribute[1] . ($attribute[2] ?? '') . ($attribute[3] ?? '')))] = true;
            }
        }

        return $names;
    }

    private static function meta(string $attribute, string $key, string $content, ?string $media = null): string
    {
        $media = $media === null ? '' : ' media="' . self::escape($media) . '"';

        return "<meta {$attribute}=\"{$key}\" content=\"" . self::escape($content) . "\"{$media}>";
    }

    /** `de-CH` → `de_CH`, the form Open Graph expects. */
    private static function ogLocale(string $locale): string
    {
        return str_replace('-', '_', trim($locale));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
