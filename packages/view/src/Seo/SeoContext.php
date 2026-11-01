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
