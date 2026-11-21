<?php

declare(strict_types=1);

namespace Preflow\View\Seo;

/**
 * The page meta one render collected and the site's defaults it reads: SeoContext's state, one
 * per render frame (a frame slot), and the context's own outside every render.
 *
 * @since 0.81.0
 */
final class SeoMeta
{
    public ?PageMeta $meta = null;

    /** @var array<string, ?PageMeta> the site's defaults per locale, when known before the middleware asks (a cached page carries them) */
    public array $defaults = [];

    /** @var ?\Closure(): ?PageMeta the site's defaults for the request being served, set by the SEO middleware */
    public ?\Closure $siteDefaults = null;

    /** The site's defaults and their resolver of this one, without its page meta. */
    public function siteDefaultsOnly(): self
    {
        $copy = new self();
        $copy->defaults = $this->defaults;
        $copy->siteDefaults = $this->siteDefaults;

        return $copy;
    }
}
