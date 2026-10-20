<?php

declare(strict_types=1);

namespace Preflow\Folio\Assets;

use Preflow\Folio\Content\ContentLocale;
use Preflow\View\AssetCollector;
use Preflow\View\ResponsiveImage;

/**
 * {{ folio_image(value, 'preset', {sizes: …}) }}: <picture> with AVIF/WebP
 * srcsets from the asset's variants, an <img> with explicit width/height, alt
 * text in the page's language and the focal point as classes (CSP forbids
 * inline styles). Options are ResponsiveImage's, merged with config/image.php
 * presets. Legacy upload paths render as a plain <img>; documents as a link.
 */
final class ImageTag
{
    private const string LEGACY_PATH = '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,8}$#';

    /** @param array<string, array<string, mixed>> $presets config/image.php "presets" */
    public function __construct(
        private readonly AssetLibrary $library,
        private readonly ContentLocale $locale,
        private readonly array $presets,
        private readonly AssetCollector $css,
        private readonly string $legacyUrlPrefix,
        private readonly ?ImageWidths $widths = null,
    ) {}

    /** @param array<string, mixed> $options */
    public function render(mixed $value, ?string $preset = null, array $options = []): string
    {
        $value = self::first($value);
        if ($value === null) {
            return '';
        }
        if (preg_match(self::LEGACY_PATH, $value) === 1) {
            $url = rtrim($this->legacyUrlPrefix, '/') . '/' . $value;
            return in_array(strtolower(pathinfo($value, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'], true)
                ? '<img src="' . self::e($url) . '" alt="" loading="lazy">'
                : '<a href="' . self::e($url) . '">' . self::e(basename($value)) . '</a>';
        }
        $asset = $this->library->find($value);
        if ($asset === null) {
            return '';
        }
        if ($asset->kind() !== 'image') {
            return '<a href="' . self::e($this->library->url($asset->path)) . '">' . self::e($asset->label()) . '</a>';
        }

        $opts = array_merge(ResponsiveImage::DEFAULTS, $preset !== null ? (array) ($this->presets[$preset] ?? []) : [], $options);
        $sizes = (string) $opts['sizes'];

        $priority = (bool) ($opts['priority'] ?? false);
        $sources = '';
        $first = null; // [format, srcset] of the first source: what the browser will pick
        foreach (['avif', 'webp'] as $format) {
            $srcset = $this->srcset($asset, $format, $preset);
            if ($srcset !== '') {
                $first ??= [$format, $srcset];
                $sources .= '<source type="image/' . $format . '" srcset="' . self::e($srcset) . '" sizes="' . self::e($sizes) . '">';
            }
        }
        if ($priority) {
            // The page's key image: fetch it before the parser reaches it.
            $this->css->addHeadTag($first !== null
                ? '<link rel="preload" as="image" type="image/' . $first[0] . '" imagesrcset="' . self::e($first[1]) . '" imagesizes="' . self::e($sizes) . '" fetchpriority="high">'
                : '<link rel="preload" as="image" href="' . self::e($this->library->url($asset->path)) . '" fetchpriority="high">', 'folio-preload:' . $asset->id . ':' . ($preset ?? ''));
        }

        $classes = array_filter([trim((string) $opts['class']), ...$this->focalClasses($asset)]);
        $alt = is_string($options['alt'] ?? null) ? $options['alt'] : $asset->altFor($this->locale->get(), $this->locale->locales()->default);

        $img = '<img src="' . self::e($this->library->url($asset->path)) . '"';
        if ($asset->width !== null && $asset->height !== null) {
            $img .= ' width="' . $asset->width . '" height="' . $asset->height . '"';
        }
        $img .= ' alt="' . self::e($alt) . '" loading="' . ($priority ? 'eager' : self::e((string) $opts['loading'])) . '" decoding="async"';
        if ($priority) {
            $img .= ' fetchpriority="high"';
        }
        if ($classes !== []) {
            $img .= ' class="' . self::e(implode(' ', $classes)) . '"';
        }
        foreach ((array) $opts['attrs'] as $name => $attr) {
            if (is_string($name) && preg_match('/^[a-z][a-z0-9-]*$/', $name) === 1 && $name !== 'style' && !str_starts_with($name, 'on') && is_scalar($attr)) {
                $img .= ' ' . $name . '="' . self::e((string) $attr) . '"';
            }
        }
        $img .= '>';

        return $sources === '' ? $img : '<picture>' . $sources . $img . '</picture>';
    }

    /**
     * With a width model: the preset's widths up to the original, by their
     * deterministic file names (step widths are made on first request); the
     * formats are those the asset already has variants in.
     */
    private function srcset(Asset $asset, string $format, ?string $preset): string
    {
        if ($this->widths !== null) {
            $has = array_filter($asset->variants, static fn (Variant $v) => $v->format === $format);
            if ($has === [] || $asset->width === null) {
                return '';
            }
            $base = substr($asset->path, 0, (int) strrpos($asset->path, '.'));

            return implode(', ', array_map(
                fn (int $w) => $this->library->url("{$base}-{$w}.{$format}") . ' ' . $w . 'w',
                $this->widths->srcsetWidths($preset, $asset->width),
            ));
        }

        $variants = array_filter($asset->variants, static fn (Variant $v) => $v->format === $format);
        usort($variants, static fn (Variant $a, Variant $b) => $a->width <=> $b->width);

        return implode(', ', array_map(fn (Variant $v) => $this->library->url($v->path) . ' ' . $v->width . 'w', $variants));
    }

    /**
     * Focal point rounded to steps of 5; a centred point needs no classes. The
     * 43 rules are registered once per page through the asset collector, which
     * emits them with the CSP nonce.
     *
     * @return list<string>
     */
    private function focalClasses(Asset $asset): array
    {
        $x = (int) (round($asset->focalX / 5) * 5);
        $y = (int) (round($asset->focalY / 5) * 5);
        if ($x === 50 && $y === 50) {
            return [];
        }

        $rules = '.folio-focal{object-position:var(--folio-fx,50%) var(--folio-fy,50%)}';
        for ($i = 0; $i <= 100; $i += 5) {
            $rules .= ".folio-fx-{$i}{--folio-fx:{$i}%}.folio-fy-{$i}{--folio-fy:{$i}%}";
        }
        $this->css->addCss($rules, 'folio-focal');

        return ['folio-focal', "folio-fx-{$x}", "folio-fy-{$y}"];
    }

    /** An id or path from a stored value: a string, a JSON list, or a PHP list. */
    private static function first(mixed $value): ?string
    {
        if (is_string($value) && str_starts_with($value, '[')) {
            $value = json_decode($value, true);
        }
        if (is_array($value)) {
            $value = array_values(array_filter($value, 'is_string'))[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
