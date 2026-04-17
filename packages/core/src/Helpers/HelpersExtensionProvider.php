<?php

declare(strict_types=1);

namespace Preflow\Core\Helpers;

use Preflow\View\ResponsiveImage;
use Preflow\View\TemplateExtensionProvider;
use Preflow\View\TemplateFunctionDefinition;

final class HelpersExtensionProvider implements TemplateExtensionProvider
{
    public function __construct(
        private readonly ?ResponsiveImage $responsiveImage = null,
    ) {}

    public function getTemplateFunctions(): array
    {
        $functions = [
            new TemplateFunctionDefinition(
                name: 'color_lighten',
                callable: fn (string $hex, float $percent): string => Color::lighten($hex, $percent),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'color_darken',
                callable: fn (string $hex, float $percent): string => Color::darken($hex, $percent),
                isSafe: true,
            ),
            new TemplateFunctionDefinition(
                name: 'color_contrast',
                callable: fn (string $color1, string $color2): float => Color::contrastRatio($color1, $color2),
            ),
            new TemplateFunctionDefinition(
                name: 'color_adjust_contrast',
                callable: fn (string $text, string $bg = '#ffffff', float $min = 4.5): string =>
                    Color::adjustForContrast($text, $bg, $min),
                isSafe: true,
            ),
        ];

        if ($this->responsiveImage !== null) {
            $img = $this->responsiveImage;
            $functions[] = new TemplateFunctionDefinition(
                name: 'responsive_image',
                callable: fn (string $path, array $options = []): string => $img->render($path, $options),
                isSafe: true,
            );
            $functions[] = new TemplateFunctionDefinition(
                name: 'image_srcset',
                callable: fn (string $path, array $options = []): string =>
                    $img->srcset(
                        $path,
                        $options['widths'] ?? [480, 768, 1024],
                        $options['format'] ?? 'webp',
                        $options['quality'] ?? 75,
                    ),
                isSafe: true,
            );
        }

        return $functions;
    }

    public function getTemplateGlobals(): array
    {
        return [];
    }
}
