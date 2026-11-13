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
        protected readonly FieldRenderer $renderer = new FieldRenderer(),
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
            'attrs' => $this->editorAttrs($ctx) + ($ctx->placeholder !== null ? ['placeholder' => $ctx->placeholder] : []),
        ];
        // Only set label when provided; FieldRenderer humanizes the name otherwise.
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }

        return $this->renderer->renderField($ctx->name, $options);
    }

    /**
     * FieldRenderer's field with its input replaced by $control (the input inside an affix or a
     * stepper) and $aside placed beside the label (a counter). $options are renderField()'s.
     *
     * @param array<string, mixed> $options
     * @param \Closure(string): string $control gets the input's HTML, returns what stands in its place
     */
    protected function renderDecorated(string $name, array $options, \Closure $control, string $aside = '', string $class = ''): string
    {
        $html = $this->renderer->renderField($name, $options);
        $input = $this->renderer->renderInput(
            $name,
            (string) ($options['type'] ?? 'text'),
            (string) ($options['value'] ?? ''),
            array_merge(['id' => $options['id'] ?? $name], $options['attrs'] ?? []),
        );
        $at = strpos($html, $input);
        if ($at === false) {
            throw new \LogicException('The input was not found in the rendered field.');
        }
        $html = substr_replace($html, $control($input), $at, strlen($input));
        if ($aside !== '') {
            $html = (string) preg_replace('~(<label for="[^"]*">.*?</label>)~s', '<div class="folio-label-row">$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $aside) . '</div>', $html, 1);
        }
        if ($class !== '') {
            $html = (string) preg_replace('~^<div class="form-group~', '<div class="form-group ' . $class, $html, 1);
        }

        return $html;
    }

    /** @return \Closure(string): string HTML-escapes for an attribute or text */
    protected static function escaper(): \Closure
    {
        return static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Extra attributes for the input element (e.g. a class). Override in subclasses.
     *
     * @return array<string, mixed>
     */
    protected function editorAttrs(FieldContext $ctx): array
    {
        return [];
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

    /** fields.js runs the controls of the scalar fields (copy, counter, stepper, date hint; 0.75). */
    public function assets(): array
    {
        return ['fields.js'];
    }
}
