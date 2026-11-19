<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * A dimension of the render context a package owns and core does not know (a tenant, an A/B
 * variant), added with RenderContext::with() and read with get(), one per class. Keep it a
 * plain value: the context may be logged or serialized, so no closure, service or secret.
 *
 * @since 0.81.0
 */
interface Facet
{
    /** This facet's value is what an anonymous visitor gets, so it does not stop the page cache. */
    public function isPublicDefault(): bool;

    /**
     * A stable string of what this facet changes in the data a render reads. It must be UTF-8
     * text (it goes into memo keys and log lines; RenderContext::jsonSerialize() hexes one that is not).
     */
    public function memoKey(): string;
}
