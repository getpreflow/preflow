<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * A yes/no checkbox. A hidden "0" sibling makes an unchecked box submit, and a
 * missing value normalizes to false, so unchecking always saves false.
 */
final class BooleanFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\DisplaysInAdmin
{
    public function key(): string
    {
        return 'boolean';
    }

    protected function inputType(): string
    {
        return 'checkbox';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $options = [
            'type' => 'checkbox',
            'value' => $this->normalizeInput($ctx->value, $ctx->config) ? '1' : '',
            'help' => $ctx->help ?? '',
            'errors' => $ctx->errors,
            'attrs' => ['value' => '1'],
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        $hidden = '<input type="hidden" name="' . htmlspecialchars($ctx->name, ENT_QUOTES, 'UTF-8') . '" value="0">';

        return $hidden . $this->renderer->renderField($ctx->name, $options);
    }

    public function normalizeInput(mixed $raw, array $config): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return in_array(is_string($raw) ? strtolower($raw) : $raw, ['1', 'on', 'true', 1], true);
    }

    public function fromStorage(mixed $value): bool
    {
        return $this->normalizeInput($value, []);
    }

    /** Yes / No in the interface language; nothing stored (null or '') shows as empty rather than "No". */
    public function renderAdmin(mixed $value, array $config): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return \Preflow\Folio\FolioText::get($this->normalizeInput($value, $config) ? 'folio.common.yes' : 'folio.common.no');
    }

    public function renderFrontend(mixed $value, array $config): string
    {
        return $this->normalizeInput($value, $config) ? 'Yes' : 'No';
    }
}
