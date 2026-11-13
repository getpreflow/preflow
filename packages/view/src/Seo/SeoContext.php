<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * The current request's page meta. Controllers, components and Folio set or
 * merge into it while rendering; the SEO middleware reads it at the end.
 */
final class SeoContext
{
    private ?PageMeta $meta = null;

    /** @var array<string, ?PageMeta> the site's defaults per locale, when known before the middleware asks (a cached page carries them) */
    private array $defaults = [];

    /** @var ?\Closure(): ?PageMeta the site's defaults for the request being served, set by the SEO middleware */
    private ?\Closure $siteDefaults = null;

    /** @param ?\Closure(list<string>): void $reportUnknown gets the keys a template's `seo({…})` gave that page meta doesn't have */
    public function __construct(private readonly ?\Closure $reportUnknown = null) {}

    public function set(PageMeta $meta): void
    {
        $this->meta = $meta;
    }

    /** Change some properties of what's set, starting from an empty meta when nothing is. */
    public function merge(array $changes): void
    {
        $this->meta = ($this->meta ?? new PageMeta())->with($changes);
    }

    /**
     * Merge what a template's `seo({…})` gives: snake_case keys (`og_type`,
     * `alternate_locales`), an image as URL or MediaLibrary::shareImage() array,
     * and `schema` as one node or a list, added to the nodes set so far.
     * Unknown keys (a typo) are left out, not an error page, and reported.
     *
     * @param array<string, mixed> $values
     */
    public function mergeTemplate(array $values): void
    {
        $known = get_object_vars(new PageMeta());
        $changes = [];
        $unknown = [];
        foreach ($values as $given => $value) {
            $key = lcfirst(str_replace('_', '', ucwords((string) $given, '_')));
            if ($key !== 'schema' && !array_key_exists($key, $known)) {
                $unknown[] = (string) $given;
                continue;
            }
            if ($key === 'modified') {
                $value = PageMeta::time($value); // a DateTime or a date string; anything else: unknown
            }
            if ($key === 'image' && is_string($value)) {
                $value = $value === '' ? null : ['url' => $value];
            }
            if ($key === 'schema' || $key === 'schemas') {
                $nodes = is_array($value) && array_is_list($value) ? $value : [$value];
                $key = 'schemas';
                $value = [...($changes['schemas'] ?? $this->meta?->schemas ?? []), ...array_filter($nodes, 'is_array')];
            }
            $changes[$key] = $value;
        }
        if ($unknown !== []) {
            $this->reportUnknown?->__invoke($unknown);
        }

        $this->merge($changes);
    }

    public function get(): ?PageMeta
    {
        return $this->meta;
    }

    public function reset(): void
    {
        $this->meta = null;
        $this->defaults = [];
        $this->siteDefaults = null;
    }

    /** Where a template's `seo_description()` finds the site's default description while the page renders. */
    public function resolveDefaultsWith(\Closure $siteDefaults): void
    {
        $this->siteDefaults = $siteDefaults;
    }

    /**
     * The description the page gets: its own, else the site's ($defaults, or those the SEO
     * middleware resolves for the request). The text as it was set, not yet cleaned.
     */
    public function mergedDescription(?PageMeta $defaults = null): string
    {
        $own = $this->meta?->description ?? '';
        if ($own !== '') {
            return $own;
        }

        return ($defaults ?? ($this->siteDefaults?->__invoke()))?->description ?? '';
    }

    /**
     * Plain text of the description the page gets (what `seo_description()` returns): no tags,
     * collapsed whitespace, not cut. '' when neither the page nor the site has one.
     */
    public function description(): string
    {
        return HeadTags::description($this->mergedDescription(), PHP_INT_MAX);
    }

    /**
     * The site's defaults for $locale, as SeoSite::defaultsFor() gave them when the page was
     * rendered: a page served from the page cache brings them along, so serving it reads
     * nothing (the defaults come from the database on a Folio site). Since 0.60.
     */
    public function useDefaults(string $locale, ?PageMeta $defaults): void
    {
        $this->defaults[$locale] = $defaults;
    }

    public function hasDefaults(string $locale): bool
    {
        return array_key_exists($locale, $this->defaults);
    }

    public function defaults(string $locale): ?PageMeta
    {
        return $this->defaults[$locale] ?? null;
    }

    /**
     * Run $next with an empty context and put the current meta back
     * afterwards, so an internal render (GEO) doesn't leak into the request.
     */
    public function scoped(\Closure $next): mixed
    {
        [$saved, $savedDefaults, $savedSite] = [$this->meta, $this->defaults, $this->siteDefaults];
        $this->meta = null;
        $this->defaults = [];
        $this->siteDefaults = null;
        try {
            return $next();
        } finally {
            [$this->meta, $this->defaults, $this->siteDefaults] = [$saved, $savedDefaults, $savedSite];
        }
    }
}
