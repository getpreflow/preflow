<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

use Preflow\Core\Context\Contexts;

/**
 * The current render's page meta. Controllers, components and Folio set or merge into it while
 * rendering; the SEO middleware reads it at the end.
 *
 * Since 0.81 each render frame has its own (a frame slot, SeoMeta): a render starts with no page
 * meta and no site defaults, and what it sets stays in its frame, so a render the app makes of
 * itself (the SEO audit, GEO, a page cache rebuild) never sees the request's and leaves nothing
 * behind. Contexts::within() keeps the frame, so it keeps the meta. Outside every render the
 * context keeps its own.
 */
final class SeoContext
{
    /** The state outside every render. */
    private SeoMeta $own;

    /** @param ?\Closure(list<string>): void $reportUnknown gets the keys a template's `seo({…})` gave that page meta doesn't have */
    public function __construct(private readonly ?\Closure $reportUnknown = null)
    {
        $this->own = new SeoMeta();
    }

    public function set(PageMeta $meta): void
    {
        $this->state()->meta = $meta;
    }

    /** Change some properties of what's set, starting from an empty meta when nothing is. */
    public function merge(array $changes): void
    {
        $state = $this->state();
        $state->meta = ($state->meta ?? new PageMeta())->with($changes);
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
                $value = [...($changes['schemas'] ?? $this->state()->meta?->schemas ?? []), ...array_filter($nodes, 'is_array')];
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
        return $this->state()->meta;
    }

    /** Where a template's `seo_description()` finds the site's default description while the page renders. */
    public function resolveDefaultsWith(\Closure $siteDefaults): void
    {
        $this->state()->siteDefaults = $siteDefaults;
    }

    public function clearDefaultsResolver(): void
    {
        $this->state()->siteDefaults = null;
    }

    /**
     * The description the page gets: its own, else the site's ($defaults, or those the SEO
     * middleware resolves for the request). The text as it was set, not yet cleaned.
     */
    public function mergedDescription(?PageMeta $defaults = null): string
    {
        $state = $this->state();
        $own = $state->meta?->description ?? '';
        if ($own !== '') {
            return $own;
        }

        return ($defaults ?? ($state->siteDefaults?->__invoke()))?->description ?? '';
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
        $this->state()->defaults[$locale] = $defaults;
    }

    public function hasDefaults(string $locale): bool
    {
        return array_key_exists($locale, $this->state()->defaults);
    }

    public function defaults(string $locale): ?PageMeta
    {
        return $this->state()->defaults[$locale] ?? null;
    }

    /**
     * The site's defaults the current render reads (those useDefaults() gave and the resolver
     * resolveDefaultsWith() set), without its page meta: for a render of the rest of the
     * pipeline only (the page cache's verify), which the SEO middleware does not see again.
     * startWith() gives them to that render's frame.
     *
     * @since 0.81.0
     */
    public function siteDefaults(): SeoMeta
    {
        return $this->state()->siteDefaultsOnly();
    }

    /**
     * The current render's page meta and site defaults become those of $state (a copy), in place
     * of what it had: the start of a render that continues another one (siteDefaults()).
     *
     * @since 0.81.0
     */
    public function startWith(SeoMeta $state): void
    {
        $current = $this->state();
        $current->meta = $state->meta;
        $current->defaults = $state->defaults;
        $current->siteDefaults = $state->siteDefaults;
    }

    /** The current render frame's state; the own one outside every render. */
    private function state(): SeoMeta
    {
        return Contexts::slot(SeoMeta::class, static fn (): SeoMeta => new SeoMeta()) ?? $this->own;
    }
}
