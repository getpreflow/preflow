<?php

declare(strict_types=1);

namespace Preflow\View;

final class ResponsiveImage
{
    private const DEFAULTS = [
        'widths' => [480, 768, 1024],
        'sizes' => '100vw',
        'format' => 'webp',
        'quality' => 75,
        'alt' => '',
        'class' => '',
        'loading' => 'lazy',
        'width' => null,
        'height' => null,
        'attrs' => [],
    ];

    /** @param array<string, array<string, mixed>> $presets */
    public function __construct(
        private readonly ImageUrlTransformer $transformer,
        private readonly array $presets = [],
    ) {}

    /** @param array<string, mixed> $options */
    public function render(string $path, array $options = []): string
    {
        $options = $this->resolveOptions($options);
        $widths = $options['widths'];
        $format = $options['format'];
        $quality = $options['quality'];
        $srcsetStr = $this->srcset($path, $widths, $format, $quality);
        $maxWidth = max($widths);
        $src = $this->transformer->transform($path, $maxWidth, $format, $quality);

        $attrs = [
            'src' => $src,
            'srcset' => $srcsetStr,
            'sizes' => $options['sizes'],
            'alt' => $options['alt'],
            'loading' => $options['loading'],
        ];
        if ($options['class'] !== '') { $attrs['class'] = $options['class']; }
        if ($options['width'] !== null) { $attrs['width'] = (string) $options['width']; }
        if ($options['height'] !== null) { $attrs['height'] = (string) $options['height']; }
        $attrs = array_merge($attrs, $options['attrs']);
        return $this->buildTag($attrs);
    }

    /** @param int[] $widths */
    public function srcset(string $path, array $widths, string $format = 'webp', int $quality = 75): string
    {
        $parts = [];
        foreach ($widths as $w) {
            $url = $this->transformer->transform($path, $w, $format, $quality);
            $parts[] = $url . ' ' . $w . 'w';
        }
        return implode(', ', $parts);
    }

    private function resolveOptions(array $options): array
    {
        $preset = $options['preset'] ?? null;
        $presetDefaults = ($preset !== null && isset($this->presets[$preset])) ? $this->presets[$preset] : [];
        return array_merge(self::DEFAULTS, $presetDefaults, $options);
    }

    private function buildTag(array $attrs): string
    {
        $html = '<img';
        foreach ($attrs as $name => $value) {
            if ($value !== null && $value !== '') {
                $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= " {$name}=\"{$escaped}\"";
            }
        }
        return $html . '>';
    }
}
