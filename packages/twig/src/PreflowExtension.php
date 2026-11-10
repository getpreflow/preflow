<?php

declare(strict_types=1);

namespace Preflow\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Preflow\View\AssetCollector;
use Preflow\View\Geo\GeoCollector;
use Preflow\View\JsPosition;
use Preflow\View\Seo\SeoContext;

final class PreflowExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssetCollector $assetCollector,
        private readonly ?GeoCollector $geoCollector = null,
        private readonly ?SeoContext $seoContext = null,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('css', $this->registerCss(...), ['is_safe' => ['html']]),
            new TwigFilter('js', $this->registerJs(...), ['is_safe' => ['html']]),
            new TwigFilter('geo', $this->registerGeo(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('head', $this->renderHead(...), ['is_safe' => ['html']]),
            new TwigFunction('assets', $this->renderAssets(...), ['is_safe' => ['html']]),
            new TwigFunction('asset_url', $this->assetUrl(...), ['is_safe' => ['html']]),
            new TwigFunction('seo', $this->registerSeo(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Filter: {% apply css %}...{% endapply %}
     * Registers CSS with the asset collector and returns empty string.
     */
    public function registerCss(string $css): string
    {
        $css = trim($css);
        if ($css !== '') {
            $this->assetCollector->addCss($css);
        }
        return '';
    }

    /**
     * Filter: {% apply js %}...{% endapply %} or {% apply js('head') %}...{% endapply %}
     * Registers JS with the asset collector and returns empty string.
     */
    public function registerJs(string $js, string $position = 'body'): string
    {
        $js = trim($js);
        if ($js !== '') {
            $pos = JsPosition::from($position);
            $this->assetCollector->addJs($js, $pos);
        }
        return '';
    }

    /**
     * Filter: {% apply geo %}...{% endapply %}
     * Records the block's Markdown with the geo collector and returns empty string.
     * Values printed inside were HTML-escaped by Twig; they are decoded back to plain text.
     */
    public function registerGeo(string $markdown): string
    {
        if ($this->geoCollector?->capturing()) {
            $this->geoCollector->add(html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    /**
     * Function: {% do seo({title: …, description: …, image: …, noindex: …, og_type: …, public_content: …, schema: […]}) %}
     * Merges the page's meta into the SEO context and returns empty string;
     * without a context (SEO off) it does nothing.
     */
    public function registerSeo(array $meta): string
    {
        $this->seoContext?->mergeTemplate($meta);
        return '';
    }

    /**
     * Function: {{ head() }}
     * Renders assets for the <head> section (head JS only).
     */
    public function renderHead(): string
    {
        return $this->assetCollector->renderHead();
    }

    /**
     * Function: {{ assets() }}
     * Renders assets for end of <body>: body JS, then inline JS.
     */
    public function renderAssets(): string
    {
        return $this->assetCollector->renderAssets();
    }

    /**
     * Function: {{ asset_url('images/logo.png') }}
     * Returns a root-relative URL for a static asset path.
     * Projects needing a CDN prefix or cache-busting can register their own
     * asset_url() via AppServiceProvider to override this default.
     */
    public function assetUrl(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}
