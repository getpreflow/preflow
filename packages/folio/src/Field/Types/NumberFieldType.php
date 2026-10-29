<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;

final class NumberFieldType extends AbstractScalarFieldType implements \Preflow\Folio\Field\HasColumnKind
{
    public function key(): string
    {
        return 'number';
    }

    public function columnKind(array $config): \Preflow\Data\Schema\ColumnKind
    {
        return \Preflow\Data\Schema\ColumnKind::Number;
    }

    protected function inputType(): string
    {
        return 'number';
    }

    /** @return array<string, mixed> */
    protected function editorAttrs(FieldContext $ctx): array
    {
        $step = $this->step($ctx->config);

        return $step === null ? [] : ['step' => $step];
    }

    /** "step": a positive number or "any". Without it the browser takes whole numbers only. */
    public function rules(array $config): array
    {
        $step = $this->step($config);
        if ($step === null) {
            return [];
        }

        return $step === 'any' ? ['numeric'] : ['numeric', 'step:' . $step];
    }

    private function step(array $config): ?string
    {
        if (!array_key_exists('step', $config) || $config['step'] === null) {
            return null;
        }
        $step = $config['step'];
        if ($step === 'any') {
            return 'any';
        }
        if (is_int($step) || is_float($step) || (is_string($step) && is_numeric($step))) {
            $text = \Preflow\Validation\Rules\Step::decimal($step);
            if ($text !== null && $text !== '0' && !str_starts_with($text, '-')) {
                return $text;
            }
        }

        throw new \LogicException('Number field option "step" must be a positive number or "any".');
    }

    /** An emptied number field holds no number. */
    public function fromStorage(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
