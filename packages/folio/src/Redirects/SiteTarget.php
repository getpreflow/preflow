<?php

declare(strict_types=1);

namespace Preflow\Folio\Redirects;

use Preflow\Data\Locales;
use Preflow\Folio\Content\FrontendResolver;
use Preflow\Folio\Content\ResolvedRecord;
use Preflow\Routing\Redirect\AreaMode;
use Preflow\Routing\Redirect\RedirectPath;

/**
 * Where a redirect target really leads, so no redirect ever points at another one (no chains):
 * Settings → Redirects and the "Send its old links to…" choice of a delete or an unpublish
 * both go through here.
 *
 * A URL target on this site (a path, or an absolute URL with the site's host) where a page
 * answers becomes that page's record: the redirect follows the page when it moves. Where an
 * entry answers instead, the entry's own final target is taken. Anything else (another host, a
 * route that is no Folio page) stays a URL.
 *
 * Addresses are read as LocaleMiddleware reads them: with locale prefixes a first segment naming
 * one of the site's languages is the language, else the default; without prefixes ''.
 */
final class SiteTarget
{
    /** Hops followed through entries that point at other entries (left over from before this check). */
    private const int MAX_HOPS = 10;

    /** @var \Closure(): LiveRecords */
    private readonly \Closure $live;

    /**
     * @param LiveRecords|\Closure(): LiveRecords $live a closure when LiveRecords is built later
     * @param bool   $prefixed whether URLs carry the locale (i18n url_strategy "prefix")
     * @param string $host     this site's host (app.url's); '' when not configured
     */
    public function __construct(
        private readonly RedirectEntries $entries,
        LiveRecords|\Closure $live,
        private readonly FrontendResolver $resolver,
        private readonly Locales $locales,
        private readonly bool $prefixed,
        private readonly string $host = '',
    ) {
        $this->live = $live instanceof LiveRecords ? static fn (): LiveRecords => $live : $live;
    }

    /** The host of an app.url ('' for none). */
    public static function hostOf(mixed $appUrl): string
    {
        $host = is_string($appUrl) ? parse_url(trim($appUrl), PHP_URL_HOST) : null;

        return is_string($host) ? $host : '';
    }

    /**
     * Where a removal target really sends visitors: a record that is live (null for a draft or an
     * unknown one), a URL on this site resolved to the page or the final target of the entry
     * answering there (null when that entry leads nowhere), any other URL as it is.
     */
    public function final(RemovalTarget $target): ?RemovalTarget
    {
        return $this->follow($target, self::MAX_HOPS);
    }

    /**
     * The entry locale and path a URL on this site lands on (a path, or an absolute URL with this
     * site's host or $host); null for another host or a path RedirectPath::normalize() rejects.
     *
     * @return array{string, string}|null
     */
    public function address(string $url, string $host = ''): ?array
    {
        $local = $this->localPath($url, $host);
        $normalized = $local === null ? null : RedirectPath::normalize($local);

        return $normalized === null ? null : $this->split($normalized);
    }

    /**
     * The page holding an address of an entry locale. Tried with the path decoded too:
     * FrontendResolver::match() compares route values unencoded, so "/%C3%BCber-uns" alone would
     * miss the page "über-uns".
     */
    public function page(string $locale, string $path): ?ResolvedRecord
    {
        $locale = $this->lookupLocale($locale);

        return $this->resolver->match($path, $locale)
            ?? ($path !== rawurldecode($path) ? $this->resolver->match(rawurldecode($path), $locale) : null);
    }

    /**
     * The entry's locale and the path without its locale prefix, for a normalized address. With
     * locale prefixes, as LocaleMiddleware reads them: a first segment of two lowercase letters
     * naming one of the site's languages (the default's too) is the language, else the default
     * language; the locale is always a real one, as RecordAddresses stores it. Without prefixes
     * the entry is language-neutral ('').
     *
     * @return array{string, string}
     */
    public function split(string $normalized): array
    {
        if (!$this->prefixed) {
            return ['', $normalized];
        }
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $normalized, $m) === 1 && $this->locales->has($m[1])) {
            return [$m[1], ($m[2] ?? '') === '' ? '/' : $m[2]];
        }

        return [$this->locales->default, $normalized];
    }

    /** The locale a request for an address of $locale has ('' on a site without locale prefixes: its default). */
    public function lookupLocale(string $locale): string
    {
        return $locale !== '' ? $locale : $this->locales->default;
    }

    private function follow(RemovalTarget $target, int $hops): ?RemovalTarget
    {
        if ($target->type !== null && $target->id !== null) {
            return ($this->live)()->find($target->type, $target->id) !== null ? $target : null;
        }
        $url = (string) $target->url;
        // A query or fragment is the editor's choice: kept as typed.
        $at = str_contains($url, '?') || str_contains($url, '#') ? null : $this->address($url);
        if ($at === null) {
            return $target;
        }
        [$locale, $path] = $at;
        $page = $this->page($locale, $path);
        if ($page !== null) {
            return RemovalTarget::record($page->route->type, (string) $page->record->getId());
        }
        [$entry, $rest] = $this->entryAt($this->lookupLocale($locale), $path);
        if ($entry === null) {
            return $target;
        }
        if ($hops === 0) {
            return null;
        }
        if ($entry['target_url'] !== null) {
            $next = $entry['area'] && $entry['mode'] === AreaMode::Prefix->value
                ? (rtrim($entry['target_url'], '/') . $rest ?: '/')
                : $entry['target_url'];
            $parsed = RemovalTarget::parse($next);

            return $parsed === null ? null : $this->follow($parsed, $hops - 1);
        }
        if ($entry['target_type'] !== null && $entry['target_id'] !== null) {
            return $this->follow(RemovalTarget::record($entry['target_type'], $entry['target_id']), $hops - 1);
        }

        return null;
    }

    /**
     * The entry answering at an address, as RedirectMiddleware looks it up: the exact one (its
     * language, else language-neutral), else the deepest area above it.
     *
     * @return array{array<string, mixed>|null, string} the entry and, for an area, the rest of the path below it
     */
    private function entryAt(string $locale, string $path): array
    {
        foreach ([$locale, ''] as $candidate) {
            $exact = $this->entries->at($candidate, $path);
            if ($exact !== null) {
                return [$exact, ''];
            }
        }
        $best = null;
        $rest = '';
        foreach ($this->entries->areas($locale) as $area) {
            $below = RedirectPath::under($path, $area->path);
            if ($below !== null && ($best === null || strlen($area->path) > strlen($best->path))) {
                $best = $area;
                $rest = $below;
            }
        }

        return [$best === null ? null : $this->entries->find($best->id), $rest];
    }

    /** The path of a URL on this site (a path, or an absolute URL with this site's host or $host), without query and fragment; else null. */
    private function localPath(string $url, string $host): ?string
    {
        if (str_starts_with($url, '/')) {
            return (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        }
        $parts = parse_url($url);
        $urlHost = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if ($urlHost === '' || !in_array(strtolower($urlHost), array_map(strtolower(...), array_filter([$this->host, $host])), true)) {
            return null;
        }

        return (string) ($parts['path'] ?? '/') ?: '/';
    }
}
