<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The value must be a whole multiple of the step, counted from 0 ("step:0.5": 2.5 passes, 2.3
 * does not). The check is exact decimal arithmetic on the value's decimal string, so there is no
 * float tolerance to lose precision on large numbers. Null and non-numeric values pass:
 * "required" and "numeric" judge those. Without bcmath, a value whose scaled digits do not fit
 * an integer is refused.
 */
final class Step implements ValidationRule
{
    private readonly string $stepText;

    public function __construct(int|float $step)
    {
        $text = self::decimal($step);
        if ($text === null || $text === '0' || str_starts_with($text, '-')) {
            throw new \InvalidArgumentException('The step rule needs a step above zero.');
        }
        $this->stepText = $text;
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || !is_numeric($value)) {
            return true;
        }
        $parsed = self::parse($value);
        if ($parsed !== null && self::multiple($parsed, self::parse($this->stepText))) {
            return true;
        }
        return new Message('validation.step', ['step' => $this->stepText]);
    }

    /**
     * A number as a plain decimal string: no exponent, no trailing zeros ("1.0E-5" gives
     * "0.00001"). Null when it is not a finite number.
     */
    public static function decimal(int|float|string $number): ?string
    {
        $parsed = self::parse($number);
        if ($parsed === null) {
            return null;
        }
        [$negative, $digits, $scale] = $parsed;
        if ($digits === '0') {
            return '0';
        }
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        $text = $scale > 0 ? substr($digits, 0, -$scale) . '.' . substr($digits, -$scale) : $digits;

        return ($negative ? '-' : '') . $text;
    }

    /** @return array{bool, string, int}|null negative, digits without leading zeros, decimal places */
    private static function parse(int|float|string $number): ?array
    {
        if (is_float($number)) {
            if (!is_finite($number)) {
                return null;
            }
            $number = sprintf('%.15g', $number);
        }
        $number = (string) $number;
        if (!preg_match('/^\s*([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?\s*$/', $number, $m)
            || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }
        $exponent = (int) ($m[4] ?? 0);
        if (abs($exponent) > 400) {
            return null;
        }
        $fraction = $m[3] ?? '';
        $digits = $m[2] . $fraction;
        $scale = strlen($fraction) - $exponent;
        if ($scale < 0) {
            $digits .= str_repeat('0', -$scale);
            $scale = 0;
        }
        while ($scale > 0 && str_ends_with($digits, '0')) {
            $digits = substr($digits, 0, -1);
            $scale--;
        }
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return [false, '0', 0];
        }

        return [$m[1] === '-', $digits, $scale];
    }

    /** @param array{bool, string, int} $value @param array{bool, string, int}|null $step */
    private static function multiple(array $value, ?array $step): bool
    {
        if ($step === null) {
            return false;
        }
        [, $valueDigits, $valueScale] = $value;
        [, $stepDigits, $stepScale] = $step;
        if ($valueDigits === '0') {
            return true;
        }
        if ($valueScale > $stepScale) {
            return false;
        }
        $valueDigits .= str_repeat('0', $stepScale - $valueScale);

        if (extension_loaded('bcmath')) {
            return bcmod($valueDigits, $stepDigits, 0) === '0';
        }
        if (strlen($valueDigits) > 18 || strlen($stepDigits) > 18) {
            return false;
        }

        return ((int) $valueDigits) % ((int) $stepDigits) === 0;
    }
}
