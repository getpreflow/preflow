<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * The value must be a whole multiple of the step, counted from 0 ("step:0.5": 2.5 passes, 2.3
 * does not). A tolerance absorbs float error (0.3 is a multiple of 0.1). Null and non-numeric
 * values pass: "required" and "numeric" judge those.
 */
final class Step implements ValidationRule
{
    public function __construct(
        private readonly int|float $step,
    ) {
        if ($step <= 0) {
            throw new \InvalidArgumentException('The step rule needs a step above zero.');
        }
    }

    public function validate(mixed $value, ValidationContext $context): true|Message
    {
        if ($value === null || !is_numeric($value)) {
            return true;
        }
        $quotient = ($value + 0) / $this->step;
        if (abs($quotient - round($quotient)) < 1e-9 * max(1, abs($quotient))) {
            return true;
        }
        return new Message('validation.step', ['step' => (string) $this->step]);
    }
}
