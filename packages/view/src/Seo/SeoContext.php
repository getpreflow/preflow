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
    }

    /**
     * Run $next with an empty context and put the current meta back
     * afterwards, so an internal render (GEO) doesn't leak into the request.
     */
    public function scoped(\Closure $next): mixed
    {
        $saved = $this->meta;
        $this->meta = null;
        try {
            return $next();
        } finally {
            $this->meta = $saved;
        }
    }
}
