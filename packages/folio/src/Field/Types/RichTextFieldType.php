<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Form\FieldRenderer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Rich-text field backed by the vendored Trix editor. HTML is sanitized on save
 * AND re-sanitized on frontend render (defense in depth), so `|raw` output is
 * always safe. Trix self-initializes from the `<trix-editor input="ID">` element
 * bound to the hidden input — no custom admin JS required.
 */
final class RichTextFieldType implements FieldType
{
    public function __construct(
        private readonly HtmlSanitizerInterface $sanitizer,
        private readonly FieldRenderer $renderer = new FieldRenderer(),
    ) {}

    public function key(): string
    {
        return 'richtext';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $name = $ctx->name;
        $id = 'folio-rt-' . $name;
        $value = (string) ($ctx->value ?? '');
        $label = $ctx->label ?? $this->renderer->humanize($name);
        $hasError = $ctx->errors !== [];

        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $wrapperClass = 'form-group' . ($hasError ? ' has-error' : '');

        $html = '<div class="' . $wrapperClass . '">' . "\n";
        $html .= '  <label for="' . $e($id) . '">' . $e($label);
        if ($ctx->required) {
            $html .= ' <span class="form-required">*</span>';
        }
        $html .= '</label>' . "\n";
        $html .= '  <input type="hidden" id="' . $e($id) . '" name="' . $e($name) . '" value="' . $e($value) . '">' . "\n";
        $html .= '  <trix-editor input="' . $e($id) . '" class="folio-richtext"></trix-editor>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) ($ctx->errors[0] ?? '')) . '</div>' . "\n";
        }
        $html .= '</div>';

        return $html;
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->sanitizer->sanitize(is_string($raw) ? $raw : '');
    }

    public function toStorage(mixed $value): mixed
    {
        return $value;
    }

    public function fromStorage(mixed $value): mixed
    {
        return $value;
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        // Re-sanitize defensively: stored values should already be clean, but
        // this guarantees safe `|raw` output regardless of provenance.
        return $this->sanitizer->sanitize(is_string($value) ? $value : '');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return ['trix.css', 'trix.js'];
    }
}
