<?php

declare(strict_types=1);

namespace Preflow\Members;

/**
 * Told the locale a member page renders in, before the page renders. Since 0.81 a member page
 * renders within Contexts::current()->in($locale), so whatever renders it (the site layout, a
 * content locale) reads the page's locale from the render context; set() is called inside that
 * render, so a narrow it does ends with the page.
 *
 * @deprecated 0.81, removed in 0.83: read Contexts::current()->locale instead of binding one
 */
interface PageLocale
{
    public function set(string $locale): void;
}
