<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\CodeOptions;
use Preflow\Fields\Values\Code;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\SearchableText;

/**
 * Code (0.91; the value is preflow/fields' Code, the option CodeOptions). A monospace textarea.
 * The code is stored as plain text, so loose search finds it. Its language is a setting of the
 * field (`"language": "php"`), not of each value: the editor shows it beside the label, and the page
 * gets `<pre><code class="language-php">` with the code escaped (`code_html(value, "php")` in a
 * template). Nothing to check: any text is code.
 */
final class CodeFieldType extends AbstractScalarFieldType implements SearchableText, DisplaysInAdmin
{
    /** How much of the code a list cell shows. */
    private const int CELL = 80;

    public function key(): string
    {
        return 'code';
    }

    protected function inputType(): string
    {
        return 'textarea';
    }

    protected function editorAttrs(FieldContext $ctx): array
    {
        return ['class' => 'folio-input-mono', 'spellcheck' => 'false', 'autocomplete' => 'off', 'wrap' => 'off', 'rows' => '10'];
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $language = CodeOptions::fromConfig($ctx->config)->language;
        $options = [
            'type' => 'textarea',
            'value' => (string) (Code::fromStored($ctx->value)->code ?? ''),
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => $this->editorAttrs($ctx) + ($ctx->placeholder !== null ? ['placeholder' => $ctx->placeholder] : []),
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }
        if ($language === null) {
            return $this->renderer->renderField($ctx->name, $options);
        }
        $aside = '<span class="folio-code-language">' . htmlspecialchars($language, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</span>';

        return $this->renderDecorated($ctx->name, $options, static fn (string $input): string => $input, $aside);
    }

    /** The code with its line breaks as "\n"; null when it is empty. */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        $code = is_string($raw) ? str_replace(["\r\n", "\r"], "\n", $raw) : '';

        return trim($code) === '' ? null : $code;
    }

    public function toStorage(mixed $value): mixed
    {
        return Code::fromStored($value)?->toStored();
    }

    public function fromStorage(mixed $value): mixed
    {
        return Code::fromStored($value)?->toStored();
    }

    /** `<pre><code class="language-…">`, the code escaped. */
    public function renderFrontend(mixed $value, array $config): string
    {
        return Code::fromStored($value, CodeOptions::fromConfig($config)->language)?->html() ?? '';
    }

    /**
     * The code on one line (list cells, read-only fields).
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $code = Code::fromStored($value);

        return $code === null ? '' : htmlspecialchars(mb_strimwidth((string) preg_replace('/\s+/', ' ', $code->code), 0, self::CELL, '…'), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
