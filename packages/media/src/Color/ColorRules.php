<?php

declare(strict_types=1);

namespace Preflow\Media\Color;

use Preflow\Media\Asset;
use Preflow\View\AssetCollector;

/**
 * An asset's colours for templates, CSP-safe like the focal point: a class
 * (media-c-{hash} of the colours, so assets with the same colours share it) whose rule
 * goes once per page into the head style that the asset collector prints with the
 * page's nonce. Never an inline style.
 *
 *     .media-c-… {--media-color:#…; --media-color-rgb:r,g,b; --media-text:#…;
 *                 --media-palette-1:#…; … --media-palette-N:#…}
 *
 * Sites write rgba(var(--media-color-rgb), .8) or color: var(--media-text).
 */
final class ColorRules
{
    /** Puts the dominant colour behind an image while it loads (with the colour class). */
    public const string PLACEHOLDER = 'media-placeholder';

    public function __construct(private readonly AssetCollector $css) {}

    /**
     * The asset's colours, rgb as "r,g,b" (no spaces, for rgba(var(…), a)); null when it has none.
     * The palette holds 1-8 colours that occur in the image, most significant first: fewer
     * than 5 for an image with fewer distinct colours (a flat one has just its colour).
     *
     * @return ?array{color: string, rgb: string, palette: list<string>, palette_rgb: list<string>, text: string}
     */
    public static function data(Asset $asset): ?array
    {
        if ($asset->color === null || preg_match('/^#[0-9a-f]{6}$/i', $asset->color) !== 1) {
            return null;
        }
        $palette = array_values(array_filter($asset->palette, static fn (string $c) => preg_match('/^#[0-9a-f]{6}$/i', $c) === 1));
        $palette = array_map('strtolower', $palette);
        $text = in_array($asset->textColor, ['#ffffff', '#000000'], true) ? $asset->textColor : ColorExtractor::textColor($asset->color);

        return [
            'color' => strtolower($asset->color),
            'rgb' => self::rgb($asset->color),
            'palette' => $palette,
            'palette_rgb' => array_map(self::rgb(...), $palette),
            'text' => $text,
        ];
    }

    /** The asset's colour class, its rule registered for this page; '' without colours. */
    public function classFor(Asset $asset): string
    {
        $data = self::data($asset);
        if ($data === null) {
            return '';
        }
        $vars = "--media-color:{$data['color']};--media-color-rgb:{$data['rgb']};--media-text:{$data['text']}";
        foreach ($data['palette'] as $i => $hex) {
            $vars .= ';--media-palette-' . ($i + 1) . ':' . $hex;
        }
        $class = 'media-c-' . substr(hash('xxh3', $vars), 0, 10);
        $this->add('.' . $class . '{' . $vars . '}', $class);

        return $class;
    }

    /** The placeholder class's rule, registered for this page. */
    public function placeholder(): string
    {
        $this->add('.' . self::PLACEHOLDER . '{background-color:var(--media-color)}', self::PLACEHOLDER);

        return self::PLACEHOLDER;
    }

    /** Page-wide: outside any component's CSS scope, so the class works wherever it is used. */
    private function add(string $rule, string $key): void
    {
        $scope = $this->css->getCssScope();
        $this->css->setCssScope(null);
        try {
            $this->css->addCss($rule, $key);
        } finally {
            $this->css->setCssScope($scope);
        }
    }

    private static function rgb(string $hex): string
    {
        return implode(',', ColorExtractor::rgb($hex));
    }
}
