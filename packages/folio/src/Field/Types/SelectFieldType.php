<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * One choice from "options" (a value => label map, or a plain list where the value is the label;
 * a label may be a map per language). Adds an in: rule automatically. A stored value that is no
 * longer an option stays visible (and selected) so editing never silently drops it.
 *
 * How it shows (board LibFields, 0.75): up to SEGMENTED_MAX options of a choice that is required or
 * has a "default" are a segmented radiogroup (an option under "", a placeholder, is no segment); "display": "cards" shows radio cards with a
 * description each ("options" as {value: {"label": …, "help": …}}); "display": "radio" radio chips;
 * "display": "select" a select (an optional one keeps "—", for no choice). Any other "display"
 * value is a boot error (TypeCatalog, through display()).
 */
final class SelectFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText, \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'select';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Short;
    }

    protected function inputType(): string
    {
        return 'select';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $options = self::options($ctx->config);
        $value = (string) ($ctx->value ?? '');

        if ($value !== '' && !array_key_exists($value, $options)) {
            $options = [$value => \Preflow\Folio\FolioText::get('folio.fields.no_longer_option', ['value' => $value])] + $options;
        }

        $display = self::display($ctx->config);
        if ($display === 'radio') {
            return $this->radios($ctx, $options, $value);
        }
        if ($display === 'cards') {
            return $this->cards($ctx, $options, self::helps($ctx->config), $value);
        }
        // An option under "" is a placeholder ("– choose –"): a radiogroup's own "nothing chosen", not a segment.
        $choices = array_diff_key($options, ['' => true]);
        if (!array_key_exists('display', $ctx->config) && count($choices) <= self::SEGMENTED_MAX
            && ($ctx->required || array_key_exists('default', $ctx->config))) {
            return $this->segmented($ctx, $choices, $value);
        }

        if (!$ctx->required) {
            $options = ['' => '—'] + $options;
        }

        $field = [
            'type' => 'select',
            'value' => $value,
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => ['options' => $options],
        ];
        if ($ctx->label !== null) {
            $field['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $field);
    }

    public function normalizeInput(mixed $raw, array $config): ?string
    {
        $value = is_scalar($raw) ? (string) $raw : '';

        return $value === '' ? null : $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $label = self::options($config)[(string) ($value ?? '')] ?? (string) ($value ?? '');

        return htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function rules(array $config): array
    {
        $values = array_keys(self::options($config));

        return $values === [] ? [] : ['in:' . implode(',', array_map('strval', $values))];
    }

    /** A required choice (or one with a default) of up to this many options is segmented. @since 0.75 */
    public const int SEGMENTED_MAX = 4;

    /**
     * The "display" option: "select", "radio" or "cards" (cards need {value: {label, help}} options);
     * absent: segmented or select, by the rule above.
     *
     * @since 0.75
     *
     * @param array<string, mixed> $config
     * @throws \LogicException otherwise (TypeCatalog reports it at boot)
     */
    public static function display(array $config): ?string
    {
        if (!array_key_exists('display', $config)) {
            return null;
        }
        $display = $config['display'];
        if (!in_array($display, ['select', 'radio', 'cards'], true)) {
            throw new \LogicException('Select field option "display" must be "select", "radio" or "cards".');
        }
        if ($display === 'cards') {
            $options = $config['options'] ?? null;
            $text = static fn (mixed $t): bool => (is_string($t) && $t !== '') || (is_array($t) && $t !== [] && !array_is_list($t) && array_filter($t, 'is_string') === $t);
            $valid = is_array($options) && $options !== [] && !array_is_list($options);
            foreach ($valid ? $options : [] as $option) {
                if (!is_array($option) || !$text($option['label'] ?? null) || (array_key_exists('help', $option) && !$text($option['help'])) || array_diff(array_keys($option), ['label', 'help']) !== []) {
                    $valid = false;
                    break;
                }
            }
            if (!$valid) {
                throw new \LogicException('Select field option "display": "cards" needs "options" as {value: {"label": …, "help": …}}.');
            }
        }

        return $display;
    }

    /** @return array<string, string> value => label */
    private static function options(array $config): array
    {
        $raw = (array) ($config['options'] ?? []);
        $out = [];
        foreach ($raw as $key => $label) {
            $text = self::text(self::isCard($label) ? $label['label'] : $label);
            $value = array_is_list($raw) ? $text : (string) $key;
            $out[$value] = $text;
        }

        return $out;
    }

    /** @return array<string, string> value => description (the "help" of a {label, help} option) */
    private static function helps(array $config): array
    {
        $out = [];
        foreach ((array) ($config['options'] ?? []) as $key => $option) {
            if (self::isCard($option) && isset($option['help'])) {
                $out[(string) $key] = self::text($option['help']);
            }
        }

        return $out;
    }

    /** An option given as {label, help}, not as a label (or a label per language). */
    private static function isCard(mixed $option): bool
    {
        return \Preflow\Data\ModelLabelKeys::isDescribedOption($option);
    }

    /** A label: a text, or a map per language (the interface language's entry; English outside the admin). */
    private static function text(mixed $label): string
    {
        return is_array($label) ? (\Preflow\Folio\Content\DisplayLabels::pick($label) ?? '') : (is_scalar($label) ? (string) $label : '');
    }

    /**
     * A segmented radiogroup (real radios), for a few options of a required choice.
     *
     * @param array<string, string> $options
     */
    private function segmented(FieldContext $ctx, array $options, string $value): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<fieldset class="form-group folio-choice-field' . ($ctx->errors !== [] ? ' has-error' : '') . '" role="radiogroup">' . $this->legend($ctx)
            . '<div class="folio-segmented folio-segmented-choice">';
        foreach ($options as $optValue => $optLabel) {
            $html .= '<label class="folio-segment"><input type="radio" name="' . $e($ctx->name) . '" value="' . $e((string) $optValue) . '"'
                . ((string) $optValue === $value ? ' checked' : '') . ($ctx->required ? ' required' : '') . '><span>' . $e($optLabel) . '</span></label>';
        }

        return $html . '</div>' . $this->below($ctx) . '</fieldset>';
    }

    /**
     * Radio cards: each option with its description (board Redirects' 301 / 302).
     *
     * @param array<string, string> $options
     * @param array<string, string> $helps
     */
    private function cards(FieldContext $ctx, array $options, array $helps, string $value): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<fieldset class="form-group folio-choice-field folio-radio-cards-field' . ($ctx->errors !== [] ? ' has-error' : '') . '" role="radiogroup">' . $this->legend($ctx)
            . '<div class="folio-radio-cards">';
        foreach ($options as $optValue => $optLabel) {
            $optValue = (string) $optValue;
            $helpId = $ctx->name . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $optValue) . '-help';
            $help = $helps[$optValue] ?? '';
            $html .= '<label class="folio-radio-card"><input type="radio" name="' . $e($ctx->name) . '" value="' . $e($optValue) . '"'
                . ($optValue === $value ? ' checked' : '') . ($ctx->required ? ' required' : '') . ($help !== '' ? ' aria-describedby="' . $e($helpId) . '"' : '') . '>'
                . '<span class="folio-radio-card-text"><span class="folio-radio-card-label">' . $e($optLabel) . '</span>'
                . ($help !== '' ? '<span class="folio-radio-card-help" id="' . $e($helpId) . '">' . $e($help) . '</span>' : '') . '</span></label>';
        }

        return $html . '</div>' . $this->below($ctx) . '</fieldset>';
    }

    private function legend(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<legend>' . $e($ctx->label ?? $this->renderer->humanize($ctx->name)) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>';
    }

    /** Help, then every error, under a group. */
    private function below(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = $ctx->help ? '<small class="form-help">' . $e($ctx->help) . '</small>' : '';
        foreach ($ctx->errors as $error) {
            $html .= '<div class="form-error">' . $e((string) $error) . '</div>';
        }

        return $html;
    }

    /**
     * Radios as chips in a borderless group: the legend is laid out as the field label above.
     *
     * @param array<string, string> $options
     */
    private function radios(FieldContext $ctx, array $options, string $value): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $legend = $ctx->label ?? $this->renderer->humanize($ctx->name);

        $html = '<fieldset class="form-group folio-choice-group' . ($ctx->errors !== [] ? ' has-error' : '') . '"><legend>' . $e($legend) . '</legend>'
            . '<div class="folio-chips">';
        foreach ($options as $optValue => $optLabel) {
            $html .= '<label class="folio-chip"><input type="radio" name="' . $e($ctx->name) . '" value="' . $e((string) $optValue) . '"'
                . ((string) $optValue === $value ? ' checked' : '')
                . ($ctx->required ? ' required' : '') . '><span class="folio-chip-label">' . $e($optLabel) . '</span></label>';
        }
        $html .= '</div>';
        foreach ($ctx->errors as $error) {
            $html .= '<div class="form-error">' . $e((string) $error) . '</div>';
        }
        if ($ctx->help) {
            $html .= '<div class="form-help">' . $e($ctx->help) . '</div>';
        }

        return $html . '</fieldset>';
    }
}
