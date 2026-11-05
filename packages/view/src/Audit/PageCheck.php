<?php

declare(strict_types=1);

namespace Preflow\View\Audit;

/**
 * A check the SEO audit runs on every audited page on its own (a missing title,
 * images without alt). Register it with AuditRegistry::addPage().
 *
 * @since 0.54
 */
interface PageCheck
{
    /** A stable id ('title.missing'); findings name it. */
    public function id(): string;

    /** @return list<Finding> */
    public function check(RenderedPage $page): array;
}
