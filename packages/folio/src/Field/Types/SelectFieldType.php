<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * One choice from "options" (a value => label map, or a plain list where the
 * value is the label). "display": "radio" renders radios instead of a select.
 * Adds an in: rule automatically. A stored value that is no longer an option
 * stays visible (and selected) so editing never silently drops it.
 */
final class SelectFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText
{
    public function key(): string
    {
        return 'select';
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
            $options = [$value => $value . ' (no longer an option)'] + $options;
        }

        if (($ctx->config['display'] ?? 'select') === 'radio') {
            return $this->radios($ctx, $options, $value);
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

    /** @return array<string, string> value => label */
    private static function options(array $config): array
    {
        $raw = (array) ($config['options'] ?? []);
        $out = [];
        foreach ($raw as $key => $label) {
            $value = array_is_list($raw) ? (string) $label : (string) $key;
            $out[$value] = (string) $label;
        }

        return $out;
    }

    /** @param array<string, string> $options */
    private function radios(FieldContext $ctx, array $options, string $value): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $legend = $ctx->label ?? $this->renderer->humanize($ctx->name);

        $html = '<fieldset class="form-group' . ($ctx->errors !== [] ? ' has-error' : '') . '"><legend>' . $e($legend) . '</legend>';
        foreach ($options as $optValue => $optLabel) {
            $html .= '<label class="form-radio"><input type="radio" name="' . $e($ctx->name) . '" value="' . $e((string) $optValue) . '"'
                . ((string) $optValue === $value ? ' checked' : '')
                . ($ctx->required ? ' required' : '') . '> ' . $e($optLabel) . '</label>';
        }
        foreach ($ctx->errors as $error) {
            $html .= '<div class="form-error">' . $e((string) $error) . '</div>';
        }
        if ($ctx->help) {
            $html .= '<div class="form-help">' . $e($ctx->help) . '</div>';
        }

        return $html . '</fieldset>';
    }
}
