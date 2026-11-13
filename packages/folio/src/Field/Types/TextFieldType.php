<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\FolioText;

/**
 * A multi-line text. With a maximum ("length", or a "max:N" rule) a counter "58 / 160" stands
 * beside the label; fields.js keeps it current, and it is announced (aria-live) only near the limit.
 */
final class TextFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText
{
    /** The counter is announced from this share of the maximum on. */
    private const float NEAR = 0.9;

    public function key(): string
    {
        return 'text';
    }

    protected function inputType(): string
    {
        return 'textarea';
    }

    /** "monospace": true renders a code-style textarea (editor only; storage unchanged). */
    protected function editorAttrs(FieldContext $ctx): array
    {
        return ($ctx->config['monospace'] ?? false) === true ? ['class' => 'folio-input-mono'] : [];
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $max = $ctx->maxLength();
        if ($max === null) {
            return parent::renderEditor($ctx);
        }

        $e = self::escaper();
        $value = (string) ($ctx->value ?? '');
        // As the editor sees it: a line break is one character.
        $count = mb_strlen(str_replace("\r\n", "\n", $value));
        $id = $ctx->name . '-counter';
        $near = $count >= $max * self::NEAR;
        $pattern = FolioText::get('folio.fields.counter');
        $counter = '<span class="folio-counter' . ($near ? ' is-near' : '') . ($count > $max ? ' is-over' : '') . '" id="' . $e($id) . '" data-folio-counter'
            . ' data-max="' . $max . '" data-text="' . $e($pattern) . '"' . ($near ? ' aria-live="polite"' : '') . '>'
            . $e(strtr($pattern, [':count' => (string) $count, ':max' => (string) $max])) . '</span>';

        $options = [
            'type' => 'textarea',
            'value' => $value,
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => $this->editorAttrs($ctx) + ['aria-describedby' => $id] + ($ctx->placeholder !== null ? ['placeholder' => $ctx->placeholder] : []),
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderDecorated($ctx->name, $options, static fn (string $input): string => $input, $counter);
    }
}
