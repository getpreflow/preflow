<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * A check the SEO audit runs once for the whole site (robots.txt, the 404 page,
 * favicons), after the pages. It renders what it needs through $renderer.
 * Register it with AuditRegistry::addSite().
 *
 * @since 0.54
 */
interface SiteCheck
{
    /** A stable id ('robots.missing'); findings name it. */
    public function id(): string;

    /** @return list<Finding> */
    public function check(PageRenderer $renderer): array;
}
