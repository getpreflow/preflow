<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Fields\CodeOptions;
use Preflow\Fields\Values\Code;
use Preflow\Folio\Field\DisplaysInAdmin;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Folio\FolioText;

/**
 * Code (0.91; the value, check and storage are preflow/fields': Values\Code, CodeOptions, ValidCode).
 * A monospace textarea and the language: a select of the field's "languages", or a text input
 * (with the languages as suggestions) when it names none. Stored as {code, language}, null for
 * no code. Structured, so not found by loose search (as a link is not).
 *
 * Posted: {name}[code], {name}[language]. The page gets `<pre><code class="language-php">` with
 * the code escaped (code_html(value) in a template).
 *
 * The check is the core's (ValidCode through FieldKinds::rules()): rules() adds none.
 */
final class CodeFieldType implements FieldType, DisplaysInAdmin
{
    private const int CELL = 80;

    public function key(): string
    {
        return 'code';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $options = CodeOptions::fromConfig($ctx->config);
        $code = Code::fromStored($ctx->value);
        $language = $code->language ?? ($code === null ? $options->defaultLanguage : null) ?? '';
        $label = $ctx->label ?? ucfirst(str_replace('_', ' ', $ctx->name));
        $id = 'folio-code-' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $ctx->name), '-');
        $t = static fn (string $key): string => $e(FolioText::get('folio.fields.code.' . $key));
        $hasError = $ctx->errors !== [];

        $html = '<fieldset class="form-group folio-code' . ($hasError ? ' has-error' : '') . '">' . "\n";
        $html .= '  <legend>' . $e($label) . ($ctx->required ? ' <span class="form-required">*</span>' : '') . '</legend>' . "\n";

        $html .= '  <div class="form-group"><label for="' . $e($id) . '-language">' . $t('language') . '</label>';
        if ($options->languages !== null) {
            $html .= '<select id="' . $e($id) . '-language" name="' . $e($ctx->name) . '[language]"><option value=""' . ($language === '' ? ' selected' : '') . '>' . $t('none') . '</option>';
            $offered = $options->languages;
            if ($language !== '' && !in_array($language, $offered, true)) {
                $offered[] = $language; // a stored language the field no longer offers stays visible (the check refuses it)
            }
            foreach ($offered as $choice) {
                $html .= '<option value="' . $e($choice) . '"' . ($choice === $language ? ' selected' : '') . '>' . $e($choice) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input type="text" id="' . $e($id) . '-language" name="' . $e($ctx->name) . '[language]" value="' . $e($language) . '" placeholder="php" autocomplete="off" spellcheck="false" maxlength="32">';
        }
        $html .= '</div>' . "\n";

        $html .= '  <div class="form-group"><label for="' . $e($id) . '-code">' . $t('code') . '</label>'
            . '<textarea id="' . $e($id) . '-code" name="' . $e($ctx->name) . '[code]" class="folio-input-mono" rows="10" spellcheck="false" autocomplete="off" wrap="off">' . $e($code->code ?? (is_string($ctx->value) ? $ctx->value : '')) . '</textarea></div>' . "\n";

        if ($ctx->help !== null && $ctx->help !== '') {
            $html .= '  <small class="form-help">' . $e($ctx->help) . '</small>' . "\n";
        }
        if ($hasError) {
            $html .= '  <div class="form-error">' . $e((string) $ctx->errors[0]) . '</div>' . "\n";
        }

        return $html . '</fieldset>';
    }

    /**
     * The submitted code and language: line breaks as "\n", the language trimmed; null when there
     * is no code (a language without code is no value).
     *
     * @return array{code: string, language: string|null}|null
     */
    public function normalizeInput(mixed $raw, array $config): mixed
    {
        if (is_string($raw)) {
            $raw = ['code' => $raw];
        }
        $input = is_array($raw) ? $raw : [];
        $code = is_string($input['code'] ?? null) ? str_replace(["\r\n", "\r"], "\n", $input['code']) : '';
        if (trim($code) === '') {
            return null;
        }
        $language = is_string($input['language'] ?? null) ? trim($input['language']) : '';

        return ['code' => $code, 'language' => $language === '' ? null : $language];
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
        return Code::fromStored($value)?->html() ?? '';
    }

    /**
     * The first line of the code with its language, on one line.
     *
     * @param array<string, mixed> $config
     */
    public function renderAdmin(mixed $value, array $config): string
    {
        $code = Code::fromStored($value);
        if ($code === null) {
            return '';
        }
        $text = mb_strimwidth((string) preg_replace('/\s+/', ' ', $code->code), 0, self::CELL, '…');

        return htmlspecialchars(($code->language !== null ? $code->language . ': ' : '') . $text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function rules(array $config): array
    {
        return [];
    }

    public function assets(): array
    {
        return [];
    }
}
