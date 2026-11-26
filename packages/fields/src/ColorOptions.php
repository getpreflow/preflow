<?php

declare(strict_types=1);

namespace Preflow\Fields;

use Preflow\Fields\Values\Color;

/**
 * A colour field's options, top-level in its config:
 *
 *     "brand": {"type": "color", "palette": ["#c00000", "#0a5ca8"], "palette_only": true}
 *     "accent": {"type": "color", "palette": {"Brand red": "#c00000", "Sky": "#0a5ca8"}}
 *
 * - "palette": the site's colours, as a list of hex colours or a map of name => hex colour (the
 *   names are the swatches' labels). Folio's editor shows them as swatches.
 * - "palette_only": true refuses any colour that is not in the palette (the check applies without
 *   Folio too). Needs a "palette".
 *
 * @since 0.91.0
 */
final readonly class ColorOptions
{
    /**
     * @param list<array{value: string, label: string|null}> $palette the colours, lowercase
     */
    public function __construct(
        public array $palette = [],
        public bool $paletteOnly = false,
    ) {}

    /** @param array<string, mixed> $config the field's config */
    public static function fromConfig(array $config): self
    {
        $palette = [];
        $given = is_array($config['palette'] ?? null) ? $config['palette'] : [];
        foreach ($given as $label => $hex) {
            $color = Color::parse($hex);
            if ($color !== null) {
                $palette[] = ['value' => $color->hex, 'label' => is_string($label) ? $label : null];
            }
        }

        return new self($palette, ($config['palette_only'] ?? false) === true && $palette !== []);
    }

    /** @return list<string> the palette's colours, for the check */
    public function values(): array
    {
        return array_map(static fn (array $entry): string => $entry['value'], $this->palette);
    }

    /**
     * @param array<string, mixed> $config the field's config (its "translatable" too)
     * @throws \LogicException
     */
    public static function check(array $config): void
    {
        FieldOptions::refuseTranslatable('color', $config);
        FieldOptions::refuseNested('color', $config, ['palette', 'palette_only']);
        if (array_key_exists('palette', $config)) {
            $palette = $config['palette'];
            if (!is_array($palette) || $palette === []) {
                throw new \LogicException('"palette" must be a list of colours such as ["#c00000", "#0a5ca8"] or a map such as {"Brand red": "#c00000"}.');
            }
            foreach ($palette as $hex) {
                if (!is_string($hex) || preg_match('/^#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $hex) !== 1) {
                    throw new \LogicException('"palette" lists ' . json_encode($hex) . '; write each colour as #rrggbb (or #rrggbbaa).');
                }
            }
        }
        if (array_key_exists('palette_only', $config)) {
            if (!is_bool($config['palette_only'])) {
                throw new \LogicException('"palette_only" must be true or false.');
            }
            if ($config['palette_only'] && !array_key_exists('palette', $config)) {
                throw new \LogicException('"palette_only" needs a "palette" naming the colours that are allowed.');
            }
        }
    }
}
