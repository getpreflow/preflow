<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

/**
 * A date ("Y-m-d"), or date and time ("Y-m-d\TH:i") with "time": true.
 * Stored as the string the browser input produces; empty is allowed unless required.
 */
final class DateFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'date';
    }

    protected function inputType(): string
    {
        return 'date';
    }

    public function renderEditor(FieldContext $ctx): string
    {
        $options = [
            'type' => ($ctx->config['time'] ?? false) === true ? 'datetime-local' : 'date',
            'value' => (string) ($ctx->value ?? ''),
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $options);
    }

    public function rules(array $config): array
    {
        return ($config['time'] ?? false) === true
            ? ['regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/']
            : ['regex:/^\d{4}-\d{2}-\d{2}$/'];
    }
}
