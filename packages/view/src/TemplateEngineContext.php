<?php

declare(strict_types=1);

namespace Preflow\View;

use Preflow\Core\PageCache\LiveParts;
use Preflow\Core\PageCache\PageCache;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\Seo\SeoContext;

/**
 * What core hands a TemplateEngineFactory: the same services it passes the built-in engines.
 *
 * - templateDirs: where file pages and layouts are found (app/pages, then the project root);
 * - assets: the page's CSS/JS and the CSP nonce (css(), js(), head(), assets() in Twig);
 * - debug: app.debug is on (strict variables, readable errors);
 * - cachePath: a folder for compiled templates (storage/cache/templates, emptied by
 *   cache:clear); it may not exist yet;
 * - geo: Markdown a template declares for GEO ({% apply geo %} in Twig), when it records;
 * - seo: the page meta seo() fills, or null while SEO is off (seo() then does nothing);
 * - liveParts / pageCache: live parts and freshness of cached pages ({% live %}, {% freshness %}).
 */
final readonly class TemplateEngineContext
{
    /**
     * @param list<string> $templateDirs
     */
    public function __construct(
        public array $templateDirs,
        public AssetCollector $assets,
        public bool $debug,
        public string $cachePath,
        public ?GeoCollector $geo = null,
        public ?SeoContext $seo = null,
        public ?LiveParts $liveParts = null,
        public ?PageCache $pageCache = null,
    ) {}
}
