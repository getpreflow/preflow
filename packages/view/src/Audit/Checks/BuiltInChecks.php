<?php

declare(strict_types=1);

namespace Preflow\View\Audit\Checks;

use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\PageRenderer;
use Preflow\View\Geo\GeoConfig;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Seo\SchemaRegistry;
use Preflow\View\Seo\SeoConfig;
use Preflow\View\Seo\SeoSite;

/**
 * The SEO audit's built-in page, run and site checks. The app adds them to its
 * AuditRegistry the first time the registry's checks are read
 * (`new AuditRegistry(fn ($r) => BuiltInChecks::register($r, …))`).
 *
 * @since 0.54
 */
final class BuiltInChecks
{
    /**
     * @param ?string $publicDir the web server's document root, whose files the checks read instead of rendering
     * @param ?SeoConfig $seo    SEO's settings; without them (or with SEO off) the SEO site checks find nothing
     * @param ?GeoConfig $geo    GEO's settings; without them (or with GEO off) the GEO checks find nothing
     */
    public static function register(
        AuditRegistry $registry,
        PageRenderer $renderer,
        PublicPages $pages,
        ?string $publicDir = null,
        ?SeoConfig $seo = null,
        ?GeoConfig $geo = null,
        ?SeoSite $site = null,
        ?SchemaRegistry $schemas = null,
    ): void {
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
        $registry->addPage(new GeoMarkdownThin($registry, $renderer, $geo ?? new GeoConfig()));

        $registry->addRun(new TitleDuplicate($registry));
        $registry->addRun(new DescriptionDuplicate($registry));
        $registry->addRun(new HreflangUnpaired($registry, $pages, $locales));
        $registry->addRun(new BrokenLinks($registry, $renderer, $publicDir));

        $seo ??= new SeoConfig();
        $registry->addRun(new GeoTypeSchemaMissing($registry, $seo));
        $registry->addSite(new SiteRobots($seo, $publicDir));
        $registry->addSite(new SiteSitemap($seo, $pages, $publicDir));
        $registry->addSite(new SiteLlms($geo ?? new GeoConfig(), $publicDir));
        $registry->addSite(new LlmsFullTooLarge($geo ?? new GeoConfig(), $publicDir));
        $registry->addSite(new SiteNotFound());
        $registry->addSite(new SiteIcons($publicDir));
        $registry->addSite(new SiteEtag($seo));
        $registry->addSite(new SiteShareImage($seo, $site ?? new SeoSite()));
        $registry->addSite(new SiteOwner($seo, $schemas ?? new SchemaRegistry()));
    }
}
