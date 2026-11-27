<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Values\Core\RichTextValue;
use Preflow\Data\Values\FieldValue;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Form\FieldRenderer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Rich-text field backed by the vendored Trix editor. HTML is cleaned on save
 * AND cleaned again on frontend render (defense in depth), so `|raw` output is
 * always safe. Trix self-initializes from the `<trix-editor input="ID">` element
 * bound to the hidden input — no custom admin JS required.
 *
 * Since 0.93 the cleaning is the core's (RichTextValue over an HtmlCleaner): Folio gives it the
 * DataManager's cleaner (a site's bound HtmlCleaner, else the core's), the one every typed write
 * cleans with, so the form, "Write a draft" and an API write store the same HTML. A Symfony
 * sanitizer given to the constructor still works (it cleans through it).
 */
final class RichTextFieldType implements FieldType, \Preflow\Folio\Field\SearchableText
{
    private ?FieldValue $value = null;

    /**
     * The HTML elements the vendored Trix editor keeps when it takes HTML in (its block and text
     * attributes: paragraphs as blocks, heading1 h1, quote blockquote, code pre, the two lists,
     * bold strong, italic em, strike del, href a, and line breaks). Anything else it flattens, an
     * h2 into bold text. "Write a draft" asks for these only.
     *
     * @since 0.77
     */
    public const array EDITOR_ELEMENTS = ['p', 'h1', 'blockquote', 'pre', 'ul', 'ol', 'li', 'strong', 'em', 'del', 'a', 'br'];

    /**
     * @param HtmlSanitizerInterface|HtmlCleaner|(\Closure(): HtmlCleaner) $sanitizer what cleans the
     *        HTML (a closure is asked on each clean, so a binding made after boot counts)
     */
    public function __construct(
        private readonly HtmlSanitizerInterface|HtmlCleaner|\Closure $sanitizer,
        private readonly FieldRenderer $renderer = new FieldRenderer(),
    ) {}

    /**
     * The core's rich text value over the cleaner given to the constructor (the cleaner is the
     * type's own, so a site that registers this type with its own sanitizer keeps it).
     */
    private function value(): FieldValue
    {
        $sanitizer = $this->sanitizer;

        return $this->value ??= new RichTextValue(static fn (): HtmlCleaner => self::cleaner($sanitizer));
    }

    /** @param HtmlSanitizerInterface|HtmlCleaner|(\Closure(): HtmlCleaner) $sanitizer */
    private static function cleaner(HtmlSanitizerInterface|HtmlCleaner|\Closure $sanitizer): HtmlCleaner
    {
        if ($sanitizer instanceof \Closure) {
            return $sanitizer();
        }
        if ($sanitizer instanceof HtmlCleaner) {
            return $sanitizer;
        }

        return new class ($sanitizer) implements HtmlCleaner {
            public function __construct(private readonly HtmlSanitizerInterface $sanitizer) {}

            public function clean(string $html): string
            {
                return $this->sanitizer->sanitize($html);
            }
        };
    }

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

    /** The posted HTML (nothing posted is ""), cleaned as the core stores it. */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->value()->toStored(is_string($raw) ? $raw : '', new TypeFieldDefinition($this->key(), $this->key(), config: $config));
    }

    /** As given: normalizeInput() cleaned it, and every typed write cleans it again (StoredCleaning). */
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
        // Cleaned again defensively: stored values should already be clean, but
        // this guarantees safe `|raw` output regardless of provenance.
        return (string) $this->value()->toOutput(is_string($value) ? $value : '', new TypeFieldDefinition($this->key(), $this->key(), config: $config));
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
