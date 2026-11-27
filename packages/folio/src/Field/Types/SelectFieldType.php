<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * One choice from "options" (a value => label map, or a plain list where the value is the label;
 * a label may be a map per language). That the value is one of the options is checked by the core
 * on every write (Preflow\Data\Validation\SelectRules, since 0.92), so rules(), recordRules() and
 * blockRules() give no rule of their own. A stored value that is no longer an option stays visible
 * (and selected) so editing never silently drops it.
 *
 * How it shows (board LibFields, 0.75): up to SEGMENTED_MAX options of a choice that is required or
 * has a "default" are a segmented radiogroup (an option under "", a placeholder, is no segment); "display": "cards" shows radio cards with a
 * description each ("options" as {value: {"label": …, "help": …}}); "display": "radio" radio chips;
 * "display": "select" a select (an optional one keeps "—", for no choice). Any other "display"
 * value is a boot error (TypeCatalog, through display()).
 *
 * "options_from": "<field>" (0.89) instead of "options": the options are the values of that field of
 * the record being edited (FieldContext::$values, each read by its own field type: a tags field's
 * list; else a JSON-text list or one text), each value its own label. While the source has no
 * values the editor shows a note naming it ("options_from_label", which the admin sets to the
 * source's label) instead of an empty control. A save accepts only one of the source's values as
 * submitted in the same save (the core's OptionsFrom); an empty value passes unless the field is
 * required.
 * A name that is not another field of the type, both "options" and "options_from", "display":
 * "cards" with it, and such a select in "list.filters" or "list.bulk" are boot errors (TypeCatalog).
 * In a block (since 0.90) the source is another field of the same block: the editor gets that
 * block's values, a save checks the block's submitted ones (the core's BlockValueChecks), and the same mistakes
 * are errors when the blocks load (BlockRegistry). A "blocks" or "matrix" field as the source is
 * refused (since 0.90), in a type and in a block: it holds entries, not values.
 */
final class SelectFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText, \Preflow\Folio\Field\HasColumnKind, \Preflow\Folio\Field\HasRecordRules, \Preflow\Folio\Field\HasBlockRules
{
    public function key(): string
    {
        return 'select';
    }

    /** The core value of this type (used without kinds, or when they hold none for the key). */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new \Preflow\Data\Values\Core\SelectValue();
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
        $source = self::sourceField($ctx->config);
        $options = $source !== null ? self::fromValues($ctx, $source) : self::options($ctx->config);
        $value = (string) ($ctx->value ?? '');
        if ($source !== null && $options === [] && $value === '') {
            return $this->noSourceValues($ctx, $source);
        }

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

    /** The posted option key (nothing posted is none), as the core stores it (SelectValue: none is null). */
    public function normalizeInput(mixed $raw, array $config): ?string
    {
        $value = $this->stored(is_scalar($raw) ? (string) $raw : '', $config);

        return is_string($value) ? $value : null;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $label = self::options($config)[(string) ($value ?? '')] ?? (string) ($value ?? '');

        return htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * None: the options are checked by the core (SelectRules) on every write, since 0.92.
     */
    public function rules(array $config): array
    {
        return [];
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
        \Preflow\Data\SelectOptionsFrom::checkDisplay($config);
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

    /**
     * The field "options_from" names, null without one.
     *
     * @since 0.89
     *
     * @param array<string, mixed> $config
     */
    public static function sourceField(array $config): ?string
    {
        $source = $config['options_from'] ?? null;

        return is_string($source) && $source !== '' ? $source : null;
    }

    /**
     * A source field's value as options: a list (a tags field's), a JSON-text list, or one text;
     * each entry trimmed, empty ones dropped, each value once.
     *
     * @since 0.89
     *
     * @return list<string>
     */
    public static function sourceValues(mixed $value): array
    {
        return \Preflow\Data\Validation\OptionsFrom::values($value);
    }

    /**
     * The "options_from" option against the type's fields: it names another field of the type, and
     * the select has no "options" beside it (\Preflow\Data\SelectOptionsFrom::check(), which a
     * block's select goes through too, since 0.90).
     *
     * @since 0.89
     *
     * @param array<string, mixed>  $config the select's field definition
     * @param list<string>          $fields the type's field names
     * @param array<string, string> $types  field => field type key, so a blocks or matrix source is refused (since 0.90)
     * @throws \LogicException otherwise (TypeCatalog reports it at boot)
     */
    public static function checkOptionsFrom(array $config, string $name, array $fields, array $types = []): void
    {
        $set = [];
        foreach ($fields as $field) {
            $set[$field] = $types[$field] ?? '';
        }
        \Preflow\Data\SelectOptionsFrom::check($config, $name, $set, 'type');
    }

    /**
     * None: the core checks "options_from" against the record's values (SelectRules), since 0.92.
     *
     * @since 0.89
     */
    public function recordRules(array $config, \Preflow\Data\TypeDefinition $type, \Preflow\Folio\Field\FieldTypeRegistry $fieldTypes): array
    {
        return [];
    }

    /**
     * None: the core checks a block's select against the same block's values (BlockValueChecks),
     * since 0.92.
     *
     * @since 0.90.0
     */
    public function blockRules(array $config, \Preflow\Blocks\BlockDefinition $block, \Preflow\Folio\Field\FieldTypeRegistry $fieldTypes): array
    {
        return [];
    }

    /** @return array<string, string> value => label: the record's values of the "options_from" field (none on a new record) */
    private static function fromValues(FieldContext $ctx, string $source): array
    {
        $values = self::sourceValues($ctx->values[$source] ?? null);

        return array_combine($values, $values);
    }

    /** The source has no values yet (a new record): a note naming it, instead of a control without choices. */
    private function noSourceValues(FieldContext $ctx, string $source): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $label = $ctx->config['options_from_label'] ?? null;
        $label = is_string($label) && $label !== '' ? $label : $this->renderer->humanize($source);

        return '<fieldset class="form-group folio-choice-field folio-options-from-empty' . ($ctx->errors !== [] ? ' has-error' : '') . '">' . $this->legend($ctx)
            . '<p class="form-help">' . $e(\Preflow\Folio\FolioText::get('folio.fields.options_from_empty', ['field' => $label])) . '</p>'
            . $this->below($ctx) . '</fieldset>';
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
