<?php

declare(strict_types=1);

namespace Preflow\Media;

use Preflow\Media\Color\ColorRules;
use Preflow\Media\Image\BoxPlan;
use Preflow\Media\Image\Formats;
use Preflow\Media\Image\ImageWidths;
use Preflow\View\AssetCollector;
use Preflow\View\ResponsiveImage;

/**
 * {{ folio_image(value, 'preset', {sizes: …}) }}: <picture> with AVIF/WebP
 * srcsets from the asset's variants, an <img> with explicit width/height, alt
 * text in the current language (LocaleProvider) and the focal point as classes (CSP forbids
 * inline styles). Options are ResponsiveImage's, merged with config/image.php
 * presets. Legacy upload paths render as a plain <img>; documents as a link.
 * Private assets have no variants: their gated URL, without a srcset.
 *
 * A document with a poster (an image asset) renders the poster; one with a preview
 * renders the preview like an image (with the preview's variants when public; the
 * gated preview URL when private). The document's alt text is used, else the poster's.
 *
 * Colours (ColorRules): colors() returns an asset's colours as data, colorClass() a
 * class carrying them as CSS variables, its rule in the nonce'd head style; the option
 * placeholder: true puts the dominant colour behind the <img> while it loads.
 *
 * An edited image renders its master (MediaLibrary::deliveryAsset()). A box preset (cover,
 * contain) renders the files of its plan (MediaLibrary::boxPlan(), {base}-{w}.{fmt}) with the
 * box's width and height; a cover box is already cut, so it gets no focal classes.
 */
final class ImageTag
{
    private const string LEGACY_PATH = '#^\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{1,8}$#';

    private ?ColorRules $colorRules = null;

    /**
     * @param array<string, array<string, mixed>> $presets config/image.php "presets"
     * @param string $legacyUrlPrefix where legacy upload paths (Y/m/{id}.{ext}) are served
     */
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly LocaleProvider $locale,
        private readonly array $presets,
        private readonly AssetCollector $css,
        private readonly string $legacyUrlPrefix,
        private readonly ?ImageWidths $widths = null,
        private readonly ?Formats $formats = null,
    ) {}

    /**
     * The public URL of an asset value (a library id, a legacy upload path, or a list of
     * either: its first), for links to files (PDFs, downloads) and custom markup; '' when
     * there is none.
     */
    public function url(mixed $value): string
    {
        $value = self::first($value);
        if ($value === null) {
            return '';
        }
        if (preg_match(self::LEGACY_PATH, $value) === 1) {
            return rtrim($this->legacyUrlPrefix, '/') . '/' . $value;
        }
        $asset = $this->library->find($value);

        return $asset !== null ? $this->library->url($asset) : '';
    }

    /**
     * The URL of an asset value's own file, never its rendered master (the same as url()
     * for an unedited asset); '' when there is none.
     */
    public function originalUrl(mixed $value): string
    {
        $value = self::first($value);
        if ($value === null || preg_match(self::LEGACY_PATH, $value) === 1) {
            return $this->url($value);
        }
        $asset = $this->library->find($value);

        return $asset !== null ? $this->library->originalUrl($asset) : '';
    }

    /** The preview URL of a document asset value (a private one's is gated); '' when there is none. */
    public function previewUrl(mixed $value): string
    {
        $value = self::first($value);
        $asset = $value !== null ? $this->library->find($value) : null;

        return $asset !== null ? ($this->library->previewUrl($asset) ?? '') : '';
    }

    /**
     * An asset value's colours (see ColorRules::data()); null without an asset or colours.
     * The palette has 1-8 entries, possibly fewer than 5 (only colours in the image).
     *
     * @return ?array{color: string, rgb: string, palette: list<string>, palette_rgb: list<string>, text: string}
     */
    public function colors(mixed $value): ?array
    {
        $asset = $this->asset($value);

        return $asset !== null ? ColorRules::data($asset) : null;
    }

    /** An asset value's colour class (its rule added once to the page's head style); '' without colours. */
    public function colorClass(mixed $value): string
    {
        $asset = $this->asset($value);

        return $asset !== null ? $this->colorRules()->classFor($asset) : '';
    }

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
        $src = $this->library->url($asset);
        $alt = $asset->altFor($this->locale->current(), $this->locale->default());
        $record = $asset; // whose box plan: the image, the poster, or the document of a preview
        if ($asset->kind() === 'document') {
            $poster = $asset->posterId !== null ? $this->library->find($asset->posterId) : null;
            if ($poster !== null && $poster->kind() === 'image') {
                [$asset, $record, $src, $alt] = [$poster, $poster, $this->library->url($poster), $alt !== '' ? $alt : $poster->altFor($this->locale->current(), $this->locale->default())];
            } elseif (($preview = $this->library->previewAsset($asset)) !== null) {
                [$asset, $src] = [$preview, (string) $this->library->previewUrl($asset)];
            }
        }
        if ($asset->kind() !== 'image') {
            return '<a href="' . self::e($src) . '">' . self::e($asset->label()) . '</a>';
        }
        $asset = $this->library->deliveryAsset($asset) ?? $asset; // an edited image: its master
        $plan = $this->plan($record, $asset, $preset);
        if ($plan !== null) {
            $src = $this->library->url("{$plan->base}-{$plan->srcWidth()}.webp");
        }

        $opts = array_merge(ResponsiveImage::DEFAULTS, $preset !== null ? (array) ($this->presets[$preset] ?? []) : [], $options);
        $sizes = (string) $opts['sizes'];

        $priority = (bool) ($opts['priority'] ?? false);
        $sources = '';
        $first = null; // [format, srcset] of the first source: what the browser will pick
        foreach ($asset->private ? [] : ['avif', 'webp'] as $format) {
            $srcset = $plan !== null ? $this->boxSrcset($plan, $format) : $this->srcset($asset, $format, $preset);
            if ($srcset !== '') {
                $first ??= [$format, $srcset];
                $sources .= '<source type="image/' . $format . '" srcset="' . self::e($srcset) . '" sizes="' . self::e($sizes) . '">';
            }
        }
        if ($priority) {
            // The page's key image: fetch it before the parser reaches it.
            $this->css->addHeadTag($first !== null
                ? '<link rel="preload" as="image" type="image/' . $first[0] . '" imagesrcset="' . self::e($first[1]) . '" imagesizes="' . self::e($sizes) . '" fetchpriority="high">'
                : '<link rel="preload" as="image" href="' . self::e($src) . '" fetchpriority="high">', 'folio-preload:' . $asset->id . ':' . ($preset ?? ''));
        }

        $classes = array_filter([trim((string) $opts['class']), ...($plan?->format->fit === 'cover' ? [] : $this->focalClasses($asset))]);
        if (($opts['placeholder'] ?? false) === true && ($colorClass = $this->colorRules()->classFor($asset)) !== '') {
            // Behind the image while it loads: the dominant colour as the <img>'s background.
            $classes = [...$classes, $colorClass, $this->colorRules()->placeholder()];
        }
        $alt = is_string($options['alt'] ?? null) ? $options['alt'] : $alt;

        $img = '<img src="' . self::e($src) . '"';
        if ($plan !== null) {
            $img .= ' width="' . $plan->width . '" height="' . $plan->height . '"';
        } elseif ($asset->width !== null && $asset->height !== null) {
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
     * The box plan of a box preset, when the delivery image has WebP variants (else the
     * preset renders as a width preset, as before boxes existed).
     */
    private function plan(Asset $record, Asset $delivery, ?string $preset): ?BoxPlan
    {
        if ($preset === null || $delivery->private || $this->formats?->get($preset)?->isBox() !== true
            || !self::hasVariants($delivery, 'webp')) {
            return null;
        }

        return $this->library->boxPlan($record, $preset);
    }

    /** A box plan's files in a format the delivery image has variants in. */
    private function boxSrcset(BoxPlan $plan, string $format): string
    {
        if (!self::hasVariants($plan->source, $format)) {
            return '';
        }

        return implode(', ', array_map(fn (int $w) => $this->library->url("{$plan->base}-{$w}.{$format}") . ' ' . $w . 'w', $plan->widths));
    }

    private static function hasVariants(Asset $asset, string $format): bool
    {
        return array_filter($asset->variants, static fn (Variant $v) => $v->format === $format) !== [];
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

    private function colorRules(): ColorRules
    {
        return $this->colorRules ??= new ColorRules($this->css);
    }

    /** The library asset of a value (not a legacy path); null when there is none. */
    private function asset(mixed $value): ?Asset
    {
        $value = self::first($value);

        return $value !== null && preg_match(self::LEGACY_PATH, $value) !== 1 ? $this->library->find($value) : null;
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
