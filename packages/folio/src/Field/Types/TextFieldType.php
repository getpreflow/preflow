<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

final class TextFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\SearchableText
{
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
}
