<?php

declare(strict_types=1);

namespace Preflow\Form;

use Preflow\View\SafeHtml;

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
     *   call?:     SafeHtml A short call's result (hd.post(...)), written as it is into the control's
     *                       opening tag; an attribute it writes may not be set by attrs as well
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
        $call     = FieldCall::of($name, $options['call'] ?? null);

        // Merge id into attrs for the input element
        $inputAttrs = array_merge(['id' => $id], $attrs);

        $inputHtml = $this->renderInput($name, $type, $value, $inputAttrs, $call);

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
     * @param SafeHtml|null $call A short call's result, written as it is at the end of the opening tag.
     *
     * @throws \InvalidArgumentException when the call writes an attribute the control already has
     */
    public function renderInput(string $name, string $type, string $value, array $attrs, ?SafeHtml $call = null): string
    {
        return match ($type) {
            'textarea'  => $this->renderTextarea($name, $value, $attrs, $call),
            'select'    => $this->renderSelect($name, $value, $attrs, $call),
            'checkbox'  => $this->renderCheckbox($name, $value, $attrs, $call),
            default     => $this->renderStandardInput($name, $type, $value, $attrs, $call),
        };
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function renderStandardInput(string $name, string $type, string $value, array $attrs, ?SafeHtml $call): string
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

        return '<input' . $this->openingAttrs($name, $merged, $call) . '>';
    }

    private function renderTextarea(string $name, string $value, array $attrs, ?SafeHtml $call): string
    {
        $base   = ['name' => $name];
        $merged = array_merge($base, $attrs);

        // The HTML parser drops one newline right after <textarea>: this guard newline is the one it
        // drops, so a value that starts with a newline keeps it.
        return '<textarea' . $this->openingAttrs($name, $merged, $call) . '>' . "\n"
            . htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</textarea>';
    }

    private function renderSelect(string $name, string $value, array $attrs, ?SafeHtml $call): string
    {
        // Extract options array before building attribute string
        $selectOptions = $attrs['options'] ?? [];
        unset($attrs['options']);

        $base   = ['name' => $name];
        $merged = array_merge($base, $attrs);

        $html = '<select' . $this->openingAttrs($name, $merged, $call) . '>';

        foreach ($selectOptions as $optValue => $optLabel) {
            $safeValue = htmlspecialchars((string) $optValue, ENT_QUOTES, 'UTF-8');
            $safeLabel = htmlspecialchars((string) $optLabel, ENT_QUOTES, 'UTF-8');
            $selected  = ((string) $optValue === $value) ? ' selected' : '';
            $html .= '<option value="' . $safeValue . '"' . $selected . '>' . $safeLabel . '</option>';
        }

        $html .= '</select>';

        return $html;
    }

    private function renderCheckbox(string $name, string $value, array $attrs, ?SafeHtml $call): string
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

        return '<input' . $this->openingAttrs($name, $merged, $call) . '>';
    }

    /**
     * The attributes of a control's opening tag: its own, then the call as it is. An attribute the
     * call writes that the control already has is refused, so none is ever written twice.
     *
     * @param array<string, mixed> $attrs
     */
    private function openingAttrs(string $name, array $attrs, ?SafeHtml $call): string
    {
        $html = $this->buildAttrString($attrs);
        if ($call === null || trim($call->html) === '') {
            return $html;
        }

        $twice = FieldCall::overlap(FieldCall::attributeNames($call), $attrs);
        if ($twice !== []) {
            throw new \InvalidArgumentException(sprintf(
                "Field '%s': its call writes %s, and attrs (or the field itself) set %s too. "
                . 'An attribute may be written once: leave it out of attrs, or change the call.',
                $name,
                implode(', ', $twice),
                count($twice) === 1 ? 'it' : 'them',
            ));
        }

        return $html . ' ' . trim($call->html);
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
