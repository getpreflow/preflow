<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\Schema\ColumnKind;
use Preflow\Fields\ColorOptions;
use Preflow\Fields\Values\Color;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\FolioText;

/**
 * A colour (0.91; the value, check and storage are preflow/fields': Values\Color, ColorOptions and
 * the core rule "color" with the palette, registered with FieldKinds::rules()). Stored as lowercase
 * "#rrggbb" or "#rrggbbaa" text, null for none.
 *
 * - Without "palette_only": a text input for the hex colour, the browser's colour picker beside
 *   it (no alpha), and the site's "palette" as swatches to click. The text input is the field; the
 *   picker and swatches only fill it (fields.js), so a save without script works the same.
 * - With "palette_only": the palette's colours as radio swatches (and "No colour"); nothing else
 *   can be posted that the check would accept.
 *
 * What a person types is read leniently: "C00", "#C00000" and "c00000" are "#cc0000" and "#c00000";
 * anything that is no colour is kept as typed so the check can say so.
 */
final class ColorFieldType implements FieldType, DisplaysInAdmin, HasColumnKind
{
    public function key(): string
    {
        return 'color';
    }

    public function columnKind(array $config): ColumnKind
    {
        return ColumnKind::Short;
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $options = ColorOptions::fromConfig($ctx->config);
        $current = Color::fromStored($ctx->value)->hex ?? (is_string($ctx->value) ? trim($ctx->value) : '');
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = 'folio-color-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ctx->name), '-');
        $hasError = $ctx->errors !== [];
        $t = static fn (string $key, array $params = []): string => $e(FolioText::get('folio.fields.color.' . $key, $params));

        if ($options->paletteOnly) {
            return $this->paletteOnly($ctx, $options, $current, $label, $hasError);
        }

        $html = '<div class="form-group folio-color' . ($hasError ? ' has-error' : '') . '" data-folio-color>' . "\n";
        $html .= '  <label for="' . $e($id) . '">' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</label>' . "\n";
        $html .= '  <div class="folio-color-row">';
        $html .= '<input type="color" class="folio-color-picker" data-folio-color-picker value="' . $e(Color::parse($current)?->opaque() ?? '#000000') . '" aria-label="' . $t('pick') . '">';
        $html .= '<input type="text" id="' . $e($id) . '" name="' . $e($ctx->name) . '" value="' . $e($current) . '" data-folio-color-text class="folio-input-mono" placeholder="' . $t('placeholder') . '" autocomplete="off" spellcheck="false" maxlength="9">';
        $html .= '<button type="button" class="folio-input-action folio-color-clear" data-folio-color-clear aria-label="' . $t('clear') . '" title="' . $t('clear') . '">&times;</button>';
        $html .= "</div>\n";
        if ($options->palette !== []) {
            $html .= '  <div class="folio-color-swatches" role="group" aria-label="' . $t('palette') . '">';
            foreach ($options->palette as $entry) {
                $name = $entry['label'] !== null ? $entry['label'] . ' ' . $entry['value'] : $entry['value'];
                $html .= '<button type="button" class="folio-swatch-button" data-folio-swatch="' . $e($entry['value']) . '" aria-label="' . $e($name) . '" title="' . $e($name) . '">' . self::swatch($entry['value']) . '</button>';
            }
            $html .= "</div>\n";
        }

        return $html . $this->foot($ctx) . '</div>';
    }

    /** The palette's colours as radio swatches, "No colour" first; a stored colour outside the palette stays visible. */
    private function paletteOnly(FieldContext $ctx, ColorOptions $options, string $current, string $label, bool $hasError): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $entries = $options->palette;
        if ($current !== '' && !in_array(strtolower($current), $options->values(), true)) {
            $entries[] = ['value' => $current, 'label' => FolioText::get('folio.fields.color.outside', ['color' => $current])];
        }
        $choice = static function (string $value, string $text, ?string $swatch, bool $checked) use ($e, $ctx): string {
            return '<label class="folio-swatch-choice"><input type="radio" name="' . $e($ctx->name) . '" value="' . $e($value) . '"' . ($checked ? ' checked' : '') . '>'
                . ($swatch !== null && Color::parse($swatch) !== null ? self::swatch($swatch) : '<span class="folio-swatch folio-swatch-none"></span>')
                . '<span class="folio-swatch-name">' . $e($text) . '</span></label>';
        };

        $html = '<fieldset class="form-group folio-color folio-color-palette' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";
        $html .= '  <div class="folio-color-choices">' . $choice('', FolioText::get('folio.fields.color.none'), null, $current === '');
        foreach ($entries as $entry) {
            $html .= $choice($entry['value'], $entry['label'] ?? $entry['value'], $entry['value'], strtolower($current) === strtolower($entry['value']));
        }

        return $html . "</div>\n" . $this->foot($ctx) . '</fieldset>';
    }

    /**
     * A colour chip as inline SVG: the colour is a `fill` attribute, so it shows without script and
     * under a strict CSP (a style attribute would be blocked).
     */
    private static function swatch(string $hex, string $class = ''): string
    {
        $color = Color::parse($hex);
        if ($color === null) {
            return '';
        }

        return '<svg class="folio-swatch' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect width="24" height="24" rx="5" fill="' . $color->hex . '"/></svg>';
    }

    private function foot(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '';
        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($ctx->errors !== []) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html;
    }

    /** The colour as "#rrggbb" or "#rrggbbaa" when it reads as one, else as typed (the check refuses it); null for none. */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return Color::parse($raw)?->toStored() ?? trim($raw);
    }

    /** A colour as "#rrggbb(aa)", text that is no colour as typed (the check refuses it, naming what to write); null for none. */
    public function toStorage(mixed $value): mixed
    {
        return Color::fromStored($value)?->toStored() ?? (is_string($value) && trim($value) !== '' ? trim($value) : null);
    }

    public function fromStorage(mixed $value): mixed
    {
        return $this->toStorage($value);
    }

    /** The colour as text; a page uses it as it likes (a style, a class map). */
    public function renderFrontend(mixed $value, array $config): string
    {
        return htmlspecialchars(Color::fromStored($value)?->toStored() ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A swatch and the hex text (no space between: a list cell reads as the plain hex text).
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $color = Color::fromStored($value);
        if ($color === null) {
            return '';
        }
        $e = htmlspecialchars($color->hex, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::swatch($color->hex, 'folio-swatch-inline') . $e;
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['fields.js'];
    }
}
