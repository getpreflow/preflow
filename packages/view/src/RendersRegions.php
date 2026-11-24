<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * A template engine that renders regions: parts of a template marked with a name (Twig
 * `{% region 'results' %}…{% endregion %}`, Blade `@region('results') … @endregion`).
 *
 * On a full render() a region prints in place, inside `<div id="{componentId}-{name}" …>` with
 * the attributes the tag gives (componentId from the context; Regions::open() builds it).
 * renderRegion() prints only the region's contents, without that element: an action that changed
 * only the region answers with them, and the element on the page stays.
 *
 * A region renders with the context its template was rendered with, never with variables the
 * template sets before it, so it renders the same on its own as in place. Region names are
 * unique in a template; two of one name are an error when the template compiles.
 *
 * Optional, beside TemplateEngineInterface: an engine without it renders whole templates only.
 *
 * @since 0.86.0
 */
interface RendersRegions
{
    /**
     * The contents of the region $region of $template (no wrapping element), rendered with
     * $context (and the engine's globals).
     *
     * @param array<string, mixed> $context
     * @throws \InvalidArgumentException $template has no region $region (the message lists regionsOf())
     */
    public function renderRegion(string $template, string $region, array $context): string;

    /**
     * The names of the regions of $template, in the order they appear.
     *
     * @return list<string>
     */
    public function regionsOf(string $template): array;
}
