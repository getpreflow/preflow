<?php

declare(strict_types=1);

namespace Preflow\Folio\Field\Types;

use Preflow\Folio\Field\FieldContext;
use Preflow\Validation\Rules\Numeric;
use Preflow\Validation\Rules\Step;
use Preflow\Validation\Rules\WhenFilled;

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

    /**
     * A stepper (board LibFields): − value unit +. The buttons (fields.js) step by "step" and stay
     * within the "min:N" / "max:N" / "between:a,b" rules; the "unit" option ("m³", "CHF") stands after
     * the value. The input stays a plain number input, so the form works without script.
     */
    public function renderEditor(FieldContext $ctx): string
    {
        $e = self::escaper();
        $unit = self::unit($ctx->config);
        $id = $ctx->name;
        $attrs = $this->editorAttrs($ctx) + ['class' => 'folio-stepper-input']
            + ($unit !== null ? ['aria-describedby' => $id . '-unit'] : [])
            + ($ctx->placeholder !== null ? ['placeholder' => $ctx->placeholder] : []);
        $options = [
            'type' => 'number',
            'value' => (string) ($ctx->value ?? ''),
            'help' => $ctx->help ?? '',
            'required' => $ctx->required,
            'errors' => $ctx->errors,
            'attrs' => $attrs,
        ];
        if ($ctx->label !== null) {
            $options['label'] = $ctx->label;
        }
        $label = $ctx->label ?? $this->renderer->humanize($ctx->name);
        [$min, $max] = self::bounds($ctx->validate);
        $down = \Preflow\Folio\FolioText::get('folio.fields.step_down', ['label' => $label]);
        $up = \Preflow\Folio\FolioText::get('folio.fields.step_up', ['label' => $label]);
        $control = static fn (string $input): string => '<div class="folio-stepper" data-folio-stepper'
            . ($min !== null ? ' data-min="' . $e($min) . '"' : '') . ($max !== null ? ' data-max="' . $e($max) . '"' : '') . '>'
            . '<button type="button" class="folio-stepper-button" data-folio-step="-1" aria-label="' . $e($down) . '" title="' . $e($down) . '">−</button>'
            . $input
            . ($unit !== null ? '<span class="folio-stepper-unit" id="' . $e($id) . '-unit">' . $e($unit) . '</span>' : '')
            . '<button type="button" class="folio-stepper-button" data-folio-step="1" aria-label="' . $e($up) . '" title="' . $e($up) . '">+</button></div>';

        return $this->renderDecorated($ctx->name, $options, $control, '', 'folio-number-field');
    }

    /** The longest "unit" text. @since 0.75 */
    public const int UNIT_MAX = 12;

    /**
     * The "unit" option: a short one-line text, or null when the field has none.
     *
     * @since 0.75
     *
     * @param array<string, mixed> $config
     * @throws \LogicException for anything else (TypeCatalog reports it at boot)
     */
    public static function unit(array $config): ?string
    {
        if (!array_key_exists('unit', $config) || $config['unit'] === null) {
            return null;
        }
        $unit = $config['unit'];
        if (!is_string($unit) || trim($unit) === '' || mb_strlen($unit) > self::UNIT_MAX || preg_match('/[\x00-\x1F\x7F]/u', $unit) === 1) {
            throw new \LogicException('Number field option "unit" must be a short text (1 to ' . self::UNIT_MAX . ' characters, one line).');
        }

        return $unit;
    }

    /**
     * The bounds the field's rules set: "min:N", "max:N", "between:a,b".
     *
     * @param list<mixed> $validate
     * @return array{?string, ?string}
     */
    private static function bounds(array $validate): array
    {
        $min = $max = null;
        $number = '-?\d+(?:\.\d+)?';
        foreach ($validate as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            $rule = trim($rule);
            if (preg_match('/^min:(' . $number . ')$/', $rule, $m) === 1) {
                $min = $m[1];
            } elseif (preg_match('/^max:(' . $number . ')$/', $rule, $m) === 1) {
                $max = $m[1];
            } elseif (preg_match('/^between:(' . $number . '),(' . $number . ')$/', $rule, $m) === 1) {
                [$min, $max] = [$m[1], $m[2]];
            }
        }

        return [$min, $max];
    }

    /**
     * "step": a positive number or "any". Without it the browser takes whole numbers only.
     * The numeric and step checks only judge a filled value: an optional field may be left
     * empty, and "required" (from the field's "validate") still refuses an empty one.
     */
    public function rules(array $config): array
    {
        $step = $this->step($config);
        if ($step === null) {
            return [];
        }

        return $step === 'any'
            ? [new WhenFilled(new Numeric())]
            : [new WhenFilled(new Numeric(), new Step((float) $step))];
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
            $text = Step::decimal($step);
            if ($text !== null && $text !== '0' && !str_starts_with($text, '-')) {
                // The step rule gets the step as a float, which holds 15 significant digits exactly.
                if (strlen(trim(str_replace('.', '', $text), '0')) > 15) {
                    throw new \LogicException('Number field option "step" may have at most 15 significant digits.');
                }

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
