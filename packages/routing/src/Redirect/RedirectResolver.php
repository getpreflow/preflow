<?php

declare(strict_types=1);

namespace Preflow\Routing\Redirect;

/**
 * The one lookup of a redirect entry for an address: the exact entry, else the longest area
 * above it, and the URL the entry points at. Used by RedirectMiddleware (a 404 that has an
 * entry) and by CanonicalPathMiddleware (an untidy address whose clean form has an entry).
 *
 * @since 0.78.2
 */
final class RedirectResolver
{
    public function __construct(
        private readonly RedirectStore $store,
        private readonly ?RedirectTargets $targets = null,
    ) {}

    /**
     * The entry for a normalised path (see RedirectPath::normalize) in a locale, with the rest
     * of the path below it when an area matched.
     *
     * @return array{0: ?Redirect, 1: string} the entry and, for an area, what follows it ('' for an exact entry)
     */
    public function find(string $path, string $locale): array
    {
        $exact = $this->store->exact($path, $locale);
        if ($exact !== null) {
            return [$exact, ''];
        }

        $best = null;
        $bestRest = '';

        foreach ($this->store->areas($locale) as $area) {
            $rest = RedirectPath::under($path, $area->path);

            if ($rest !== null && ($best === null || strlen($area->path) > strlen($best->path))) {
                $best = $area;
                $bestRest = $rest;
            }
        }

        return [$best, $bestRest];
    }

    /** The URL an entry points at, or null when its target cannot be resolved. */
    public function url(Redirect $entry, string $rest, string $locale): ?string
    {
        if ($entry->targetUrl !== null && $entry->targetUrl !== '') {
            if ($entry->area && $entry->mode === AreaMode::Prefix) {
                $url = rtrim($entry->targetUrl, '/') . $rest;

                return $url === '' ? '/' : $url;
            }

            return $entry->targetUrl;
        }

        if ($this->targets === null || $entry->targetType === null || $entry->targetId === null) {
            return null;
        }

        return $this->targets->url($entry->targetType, $entry->targetId, $entry->locale !== '' ? $entry->locale : $locale);
    }

    /**
     * The entry and its URL, or null when there is no entry or its target cannot be resolved.
     *
     * @return array{entry: Redirect, url: string}|null
     */
    public function resolve(string $path, string $locale): ?array
    {
        [$entry, $rest] = $this->find($path, $locale);
        if ($entry === null) {
            return null;
        }

        $url = $this->url($entry, $rest, $locale);

        return $url === null ? null : ['entry' => $entry, 'url' => $url];
    }

    /** Whether an entry (exact or area) answers the path, whatever its target. */
    public function has(string $path, string $locale): bool
    {
        return $this->find($path, $locale)[0] !== null;
    }

    /** The URL with the visitor's query string added (before a fragment, after an existing query). */
    public static function withQuery(string $url, string $query): string
    {
        if ($query === '') {
            return $url;
        }

        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $query . $fragment;
    }
}
