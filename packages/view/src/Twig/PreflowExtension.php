<?php

declare(strict_types=1);

namespace Preflow\View\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Preflow\View\AssetCollector;
use Preflow\View\JsPosition;

final class PreflowExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssetCollector $assetCollector,
    ) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('css', $this->registerCss(...), ['is_safe' => ['html']]),
            new TwigFilter('js', $this->registerJs(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('head', $this->renderHead(...), ['is_safe' => ['html']]),
            new TwigFunction('assets', $this->renderAssets(...), ['is_safe' => ['html']]),
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
     * Function: {{ head() }}
     * Renders assets for the <head> section (head JS only).
     */
    public function renderHead(): string
    {
        return $this->assetCollector->renderHead();
    }

    /**
     * Function: {{ assets() }}
     * Renders assets for end of <body> (CSS + body JS).
     */
    public function renderAssets(): string
    {
        return $this->assetCollector->renderAssets();
    }
}
