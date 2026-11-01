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
     *
     * @param array<string, mixed> $values
     */
    public function mergeTemplate(array $values): void
    {
        $changes = [];
        foreach ($values as $key => $value) {
            $key = lcfirst(str_replace('_', '', ucwords((string) $key, '_')));
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
