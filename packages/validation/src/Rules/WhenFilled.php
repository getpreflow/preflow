<?php

declare(strict_types=1);

namespace Preflow\Validation\Rules;

use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationRule;

/**
 * Runs the wrapped rules only on a filled value: null and '' pass, so an optional field can be
 * left empty while "required" (outside the wrapper) still judges emptiness. Unlike "nullable" it
 * leaves the rest of the chain alone. A filled value answers with the first failing rule.
 *
 * Stringable (unique per instance), so rule lists that dedupe by string keep it.
 */
final class WhenFilled implements ValidationRule, \Stringable
{
    /** @var list<ValidationRule> */
    private readonly array $rules;

    public function __construct(ValidationRule ...$rules)
    {
        $this->rules = array_values($rules);
    }

    public function validate(mixed $value, ValidationContext $context): true|string|Message
    {
        if ($value === null || $value === '') {
            return true;
        }
        foreach ($this->rules as $rule) {
            $result = $rule->validate($value, $context);
            if ($result !== true) {
                return $result;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return self::class . '#' . spl_object_id($this);
    }
}
