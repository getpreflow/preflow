<?php

declare(strict_types=1);

namespace Preflow\Form;

final class FieldRenderer
{
    /**
     * Standard input types that use a self-closing <input> tag.
     */
    private const STANDARD_INPUTS = [
        'text', 'email', 'password', 'number', 'tel', 'url',
        'date', 'datetime-local', 'hidden', 'file', 'checkbox',
        'radio', 'color', 'range', 'search', 'time', 'week', 'month',
    ];

    /**
     * Render a complete field block: wrapper div + label + input + help + errors.
     *
     * @param string $name    Field name attribute.
     * @param array  $options {
     *   type?:     string   Input type (default: 'text')
     *   value?:    string   Current value
     *   label?:    string   Label text (auto-generated from $name if absent)
     *   id?:       string   Element id (defaults to $name)
     *   required?: bool     Whether to show required marker
     *   help?:     string   Help text shown below the input
     *   errors?:   string[] List of error messages
     *   width?:    string   Optional width modifier class suffix
     *   attrs?:    array    Extra HTML attributes passed to renderInput
     * }
     */
    public function renderField(string $name, array $options): string
    {
        $type     = $options['type']     ?? 'text';
        $value    = (string) ($options['value'] ?? '');
        $label    = $options['label']    ?? $this->humanize($name);
        $id       = $options['id']       ?? $name;
        $required = (bool) ($options['required'] ?? false);
        $help     = $options['help']     ?? '';
        $errors   = $options['errors']   ?? [];
        $width    = $options['width']    ?? '';
        $attrs    = $options['attrs']    ?? [];

        // Merge id into attrs for the input element
        $inputAttrs = array_merge(['id' => $id], $attrs);

        $inputHtml = $this->renderInput($name, $type, $value, $inputAttrs);

        $hasError = !empty($errors);

        // Build wrapper class
        $wrapperClass = 'form-group';
        if ($hasError) {
            $wrapperClass .= ' has-error';
        }
        if ($width !== '') {
            $wrapperClass .= ' form-width-' . htmlspecialchars($width, ENT_QUOTES, 'UTF-8');
        }

        $html = '<div class="' . $wrapperClass . '">' . "\n";

        // Label (not rendered for hidden inputs)
        if ($type !== 'hidden') {
            $safeId    = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $html .= '  <label for="' . $safeId . '">' . $safeLabel;
            if ($required) {
                $html .= ' <span class="form-required">*</span>';
            }
            $html .= '</label>' . "\n";
        }

        $html .= '  ' . $inputHtml . "\n";

        if ($help !== '') {
            $html .= '  <small class="form-help">' . htmlspecialchars($help, ENT_QUOTES, 'UTF-8') . '</small>' . "\n";
        }

        if ($hasError) {
            $firstError = htmlspecialchars((string) reset($errors), ENT_QUOTES, 'UTF-8');
            $html .= '  <div class="form-error">' . $firstError . '</div>' . "\n";
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render just the input element for the given type.
     *
     * @param string $name  Name attribute.
     * @param string $type  Input type (text, textarea, select, checkbox, …).
     * @param string $value Current value.
     * @param array  $attrs Extra HTML attributes. For select, pass 'options' key as assoc array.
     */
    public function renderInput(string $name, string $type, string $value, array $attrs): string
    {
        return match ($type) {
            'textarea'  => $this->renderTextarea($name, $value, $attrs),
            'select'    => $this->renderSelect($name, $value, $attrs),
            'checkbox'  => $this->renderCheckbox($name, $value, $attrs),
            default     => $this->renderStandardInput($name, $type, $value, $attrs),
        };
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function renderStandardInput(string $name, string $type, string $value, array $attrs): string
    {
        $base = [
            'type' => $type,
            'name' => $name,
        ];

        // For file inputs value attribute is not used
        if ($type !== 'file') {
            $base['value'] = $value;
        }

        $merged = array_merge($base, $attrs);

        return '<input' . $this->buildAttrString($merged) . '>';
    }

    private function renderTextarea(string $name, string $value, array $attrs): string
    {
        $base   = ['name' => $name];
        $merged = array_merge($base, $attrs);

        return '<textarea' . $this->buildAttrString($merged) . '>'
            . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</textarea>';
    }

    private function renderSelect(string $name, string $value, array $attrs): string
    {
        // Extract options array before building attribute string
        $selectOptions = $attrs['options'] ?? [];
        unset($attrs['options']);

        $base   = ['name' => $name];
        $merged = array_merge($base, $attrs);

        $html = '<select' . $this->buildAttrString($merged) . '>';

        foreach ($selectOptions as $optValue => $optLabel) {
            $safeValue = htmlspecialchars((string) $optValue, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars((string) $optLabel, ENT_QUOTES, 'UTF-8');
            $selected  = ((string) $optValue === $value) ? ' selected' : '';
            $html .= '<option value="' . $safeValue . '"' . $selected . '>' . $safeLabel . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private function renderCheckbox(string $name, string $value, array $attrs): string
    {
        $checked = in_array($value, ['1', 'true', 'on'], true);

        $base = [
            'type' => 'checkbox',
            'name' => $name,
        ];

        $merged = array_merge($base, $attrs);

        if ($checked) {
            $merged['checked'] = true;
        }

        return '<input' . $this->buildAttrString($merged) . '>';
    }

    /**
     * Build an HTML attribute string from an associative array.
     *
     * Boolean true renders as a bare attribute name (e.g. checked, required).
     * Boolean false or null removes the attribute entirely.
     * All other values are HTML-escaped.
     */
    private function buildAttrString(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $key => $val) {
            if ($val === false || $val === null) {
                continue;
            }

            $safeKey = htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8');

            if ($val === true) {
                $parts[] = $safeKey;
            } else {
                // Values should already be escaped where needed (value, options)
                // but run through escaping for attr values that haven't been
                $parts[] = $safeKey . '="' . htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') . '"';
            }
        }

        return $parts !== [] ? ' ' . implode(' ', $parts) : '';
    }

    /**
     * Convert a field name to a human-readable label.
     *
     * Examples:
     *   first_name   → First Name
     *   minPlayers   → Min Players
     *   BGGGenius    → BGG Genius
     */
    public function humanize(string $name): string
    {
        // Split on underscores
        $name = str_replace('_', ' ', $name);

        // Insert spaces before uppercase letters that follow lowercase letters (camelCase)
        $name = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);

        // Insert spaces before a sequence of uppercase letters followed by a lowercase letter
        $name = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $name);

        return ucwords($name);
    }
}
