<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldType;
use Preflow\Form\FieldRenderer;

/**
 * Shared behavior for plain scalar fields. Subclasses only declare their key
 * and the HTML input type they render.
 */
abstract class AbstractScalarFieldType implements FieldType
{
    public function __construct(
        private readonly FieldRenderer $renderer = new FieldRenderer(),
    ) {}

    abstract public function key(): string;

    /** HTML input type: 'text', 'textarea', 'number'. */
    abstract protected function inputType(): string;

    public function renderEditor(FieldContext $ctx): string
    {
        $options = [
            'type' => $this->inputType(),
            'value' => (string) ($ctx->value ?? ''),
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
        ];
        // Only set label when provided; FieldRenderer humanizes the name otherwise.
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $options);
    }

    public function normalizeInput(mixed $raw, array $config): mixed
    {
        return is_string($raw) ? $raw : (string) ($raw ?? '');
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
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
