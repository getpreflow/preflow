<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Seo\PublicPages;

/**
 * The SEO audit's built-in page and run checks. The app adds them to its
 * AuditRegistry the first time the registry's checks are read
 * (`new AuditRegistry(fn ($r) => BuiltInChecks::register($r, …))`).
 *
 * @since 0.54
 */
final class BuiltInChecks
{
    /** @param ?string $publicDir the web server's document root, whose files the link check doesn't render */
    public static function register(AuditRegistry $registry, PageRenderer $renderer, PublicPages $pages, ?string $publicDir = null): void
    {
        $locales = $renderer->locales();

        $registry->addPage(new TitleMissing($registry));
        $registry->addPage(new DescriptionMissing($registry));
        $registry->addPage(new ImageAltMissing($registry));
        $registry->addPage(new ShareImageAltMissing($registry));
        $registry->addPage(new HeadingCount($registry));
        $registry->addPage(new AriaHiddenFocusable($registry));
        $registry->addPage(new CanonicalMissing($registry));
        $registry->addPage(new CanonicalForeignHost($registry, $renderer->origin()));
        $registry->addPage(new NoindexInSitemap($registry, $pages, $locales));
        $registry->addPage(new HreflangXDefaultMissing($registry, count($locales) > 1));

        $registry->addRun(new TitleDuplicate($registry));
        $registry->addRun(new DescriptionDuplicate($registry));
        $registry->addRun(new HreflangUnpaired($registry, $pages, $locales));
        $registry->addRun(new BrokenLinks($registry, $renderer, $publicDir));
    }
}
