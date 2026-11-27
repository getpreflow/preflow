<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\Values\JsonText;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;

/**
 * JSON as text (0.91; the check is the core's rule "json", registered with FieldKinds::rules();
 * the value is preflow/fields' JsonText). A monospace textarea. The text is stored as the editor
 * wrote it, trimmed (layout and key order stay), and null when empty. Not searched.
 *
 * The page gets the text in `<pre><code class="language-json">`, escaped; json_data(value) gives
 * the decoded value for a template that wants to use it. The list cell is the first characters on
 * one line.
 */
final class JsonFieldType extends AbstractScalarFieldType implements DisplaysInAdmin
{
    /** How much of the JSON a list cell shows. */
    private const int CELL = 80;

    public function key(): string
    {
        return 'json';
    }

    /** The core value of this type (used without kinds, or when they hold none for the key). */
    protected function defaultValue(): \Preflow\Data\Values\FieldValue
    {
        return new \Preflow\Fields\Values\Field\JsonValue();
    }

    protected function inputType(): string
    {
        return 'textarea';
    }

    protected function editorAttrs(FieldContext $ctx): array
    {
        return ['class' => 'folio-input-mono', 'spellcheck' => 'false', 'autocomplete' => 'off', 'rows' => '8'];
    }

    public function renderEditor(FieldContext $ctx): string
    {
        // A value a seed stored as an array reads as its JSON text.
        return parent::renderEditor(new FieldContext(
            name: $ctx->name, label: $ctx->label, help: $ctx->help, value: JsonText::fromStored($ctx->value) ?? '',
            errors: $ctx->errors, config: $ctx->config, required: $ctx->required, placeholder: $ctx->placeholder,
            nestedErrors: $ctx->nestedErrors, locale: $ctx->locale, locales: $ctx->locales, record: $ctx->record,
            validate: $ctx->validate, length: $ctx->length, address: $ctx->address, values: $ctx->values,
        ));
    }

    /** The posted text with "\n" line breaks (nothing else is taken), as the core stores it (JsonValue: trimmed, blank is null). */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return $this->stored(is_string($raw) ? str_replace("\r\n", "\n", $raw) : null, $config);
    }

    /** The JSON as text for the editor (the core's output is the decoded value). */
    public function fromStorage(mixed $value): mixed
    {
        return JsonText::fromStored($value);
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        $text = JsonText::fromStored($value);

        return $text === null ? '' : '<pre><code class="language-json">' . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
    }

    /** @param array<string, mixed> $config */
    public function renderAdmin(mixed $value, array $config): string
    {
        $text = JsonText::fromStored($value);
        if ($text === null) {
            return '';
        }

        return htmlspecialchars(mb_strimwidth((string) preg_replace('/\s+/', ' ', $text), 0, self::CELL, '…'), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
